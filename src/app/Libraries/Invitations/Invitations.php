<?php

declare(strict_types=1);

namespace App\Libraries\Invitations;

use App\Domain\Auth\AccountExists;
use App\Domain\Auth\Role;
use App\Domain\Invitations\InvalidInvitation;
use App\Domain\Invitations\InvitationMail;
use App\Domain\Invitations\InvitationRules;
use App\Domain\Invitations\InvitationStatus;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Invitations\NewAccount;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Status\IllegalTransition;
use App\Models\Invitation;
use App\Models\InvitationRepository;

/**
 * Making, revoking and accepting invitations (docs/invitations.md). Each
 * change takes the invitation's row lock first, so two people using one link
 * at once take turns, and the second finds it used (11.).
 */
final readonly class Invitations
{
    public function __construct(
        private InvitationRepository $invitations,
        private IdentityPort $identity,
        private ClockPort $clock,
        private QueuePort $queue,
    ) {
    }

    /**
     * A new invitation, and its email queued in the same transaction
     * (docs/mail.md, 2.). Its link is issued when the email is sent
     * (issueLink), and never stored (3., 12.). An earlier pending invitation
     * for the same address is revoked (6.).
     *
     * @throws InvalidInvitation
     */
    public function invite(int $inviterId, ?Role $inviterRole, mixed $email, mixed $level): Invitation
    {
        $address = is_string($email) ? strtolower(trim($email)) : '';

        if (strlen($address) > 249 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidInvitation::because('Give a valid email address.');
        }

        $role = Role::tryFrom(is_string($level) ? $level : '');

        if ($role === null || !InvitationRules::mayInvite($inviterRole, $role)) {
            throw InvalidInvitation::because('You cannot invite someone at that level.');
        }

        if ($this->invitations->accountExists($address)) {
            throw InvalidInvitation::because('That address already has an account.');
        }

        return $this->invitations->transactional(function () use ($address, $role, $inviterId): Invitation {
            $now = $this->clock->now();

            foreach ($this->invitations->pendingFor($address) as $earlier) {
                $this->invitations->decide($earlier, InvitationStatus::Revoked, $now);
            }

            // A placeholder link nobody is given, until the email issues one.
            [, $hash] = InvitationToken::issue();
            $id = $this->invitations->insert($address, $role, $hash, $inviterId, $now, $now->modify(InvitationRules::EXPIRES_IN));
            $this->queue->enqueue(InvitationMail::of($id));

            return $this->mustFind($id);
        });
    }

    /**
     * A new link for a pending, unexpired invitation, which replaces any
     * earlier one: the token, for the email, and only its hash stored (3.).
     * The worker passes `$deliver`, which sends it: the new link is kept only
     * once that has worked, so a send that fails leaves the earlier link
     * working, whatever transaction it runs in (docs/mail.md, 4., 7.).
     *
     * @param (callable(Invitation,string):void)|null $deliver
     *
     * @return array{0:Invitation,1:string}
     *
     * @throws InvitationNotFound
     */
    public function issueLink(int $id, ?callable $deliver = null): array
    {
        return $this->invitations->transactional(function () use ($id, $deliver): array {
            $invitation = $this->invitations->find($id, true);

            if ($invitation === null || !$invitation->opensAt(InvitationRepository::time($this->clock->now()))) {
                throw InvitationNotFound::link();
            }

            [$token, $hash] = InvitationToken::issue();

            if ($deliver !== null) {
                $deliver($invitation, $token);
            }

            $this->invitations->setTokenHash($id, $hash);

            return [$this->mustFind($id), $token];
        });
    }

    /**
     * Emails a pending invitation again, with a new link, at a level the
     * sender could have invited at (4.).
     *
     * @throws InvitationNotFound|IllegalTransition|InvalidInvitation
     */
    public function sendAgain(int $id, ?Role $senderRole): Invitation
    {
        return $this->invitations->transactional(function () use ($id, $senderRole): Invitation {
            $invitation = $this->invitations->find($id, true) ?? throw InvitationNotFound::id();

            if (!InvitationRules::mayInvite($senderRole, $invitation->role)) {
                throw InvalidInvitation::because('You cannot send an invitation at that level.');
            }

            if ($invitation->status !== InvitationStatus::Pending) {
                throw IllegalTransition::between(InvitationStatus::machineName(), $invitation->status->value, 'sent again');
            }

            $this->queue->enqueue(InvitationMail::of($id));

            return $invitation;
        });
    }

    /**
     * Revokes a pending invitation, at a level the revoker could have invited
     * at: an administrator revokes users' invitations only (4.).
     *
     * @throws InvitationNotFound|IllegalTransition|InvalidInvitation
     */
    public function revoke(int $id, ?Role $revokerRole): Invitation
    {
        return $this->invitations->transactional(function () use ($id, $revokerRole): Invitation {
            $invitation = $this->invitations->find($id, true) ?? throw InvitationNotFound::id();

            if (!InvitationRules::mayInvite($revokerRole, $invitation->role)) {
                throw InvalidInvitation::because('You cannot revoke an invitation at that level.');
            }

            $this->invitations->decide($invitation, InvitationStatus::Revoked, $this->clock->now());

            return $this->mustFind($id);
        });
    }

    /**
     * The invitation a link opens, if it opens one (1., 2., 8.).
     *
     * @throws InvitationNotFound
     */
    public function open(string $token): Invitation
    {
        $hash = InvitationToken::hash($token) ?? throw InvitationNotFound::link();
        $invitation = $this->invitations->findByTokenHash($hash);

        if ($invitation === null || !$invitation->opensAt(InvitationRepository::time($this->clock->now()))) {
            throw InvitationNotFound::link();
        }

        return $invitation;
    }

    /**
     * Makes the invited account at the invited level, and uses up the link.
     *
     * @throws InvalidInvitation|InvitationNotFound
     */
    public function accept(string $token, mixed $name, mixed $password): Invitation
    {
        $account = NewAccount::parse($name, $password);
        $hash = InvitationToken::hash($token) ?? throw InvitationNotFound::link();

        return $this->invitations->transactional(function () use ($hash, $account): Invitation {
            $now = $this->clock->now();
            $invitation = $this->invitations->findByTokenHash($hash, true);

            if ($invitation === null || !$invitation->opensAt(InvitationRepository::time($now))) {
                throw InvitationNotFound::link();
            }

            try {
                $userId = $this->identity->createAccount($invitation->email, $account->password, $account->name);
            } catch (AccountExists) {
                throw InvalidInvitation::because('That address already has an account. Sign in instead.');
            }

            $this->identity->setRole($userId, $invitation->role);
            $this->invitations->decide($invitation, InvitationStatus::Accepted, $now, $userId);

            return $this->mustFind($invitation->id);
        });
    }

    private function mustFind(int $id): Invitation
    {
        return $this->invitations->find($id) ?? throw new \LogicException('The invitation just written is missing.');
    }
}
