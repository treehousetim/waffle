<?php

declare(strict_types=1);

namespace App\Libraries\Invitations;

use App\Domain\Auth\AccountExists;
use App\Domain\Auth\Role;
use App\Domain\Invitations\InvalidInvitation;
use App\Domain\Invitations\InvitationRules;
use App\Domain\Invitations\InvitationStatus;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Invitations\NewAccount;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
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
    ) {
    }

    /**
     * A new invitation, and its token: shown this once, never stored (3., 12.).
     * An earlier pending invitation for the same address is revoked (6.).
     *
     * @return array{0:Invitation,1:string}
     *
     * @throws InvalidInvitation
     */
    public function invite(int $inviterId, ?Role $inviterRole, mixed $email, mixed $level): array
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

        return $this->invitations->transactional(function () use ($address, $role, $inviterId): array {
            $now = $this->clock->now();

            foreach ($this->invitations->pendingFor($address) as $earlier) {
                $this->invitations->decide($earlier, InvitationStatus::Revoked, $now);
            }

            [$token, $hash] = InvitationToken::issue();
            $id = $this->invitations->insert($address, $role, $hash, $inviterId, $now, $now->modify(InvitationRules::EXPIRES_IN));

            return [$this->mustFind($id), $token];
        });
    }

    /** @throws InvitationNotFound|IllegalTransition */
    public function revoke(int $id): Invitation
    {
        return $this->invitations->transactional(function () use ($id): Invitation {
            $invitation = $this->invitations->find($id, true) ?? throw InvitationNotFound::id();
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
