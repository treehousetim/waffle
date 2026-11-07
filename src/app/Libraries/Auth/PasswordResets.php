<?php

declare(strict_types=1);

namespace App\Libraries\Auth;

use App\Domain\Auth\InvalidReset;
use App\Domain\Auth\PasswordResetEmail;
use App\Domain\Auth\PasswordResetMail;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Invitations\NewAccount;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\SendMail;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Domain\Ports\QueuePort;
use App\Models\PasswordReset;
use App\Models\PasswordResetRepository;
use App\Models\UserRepository;

/**
 * Resetting a forgotten password by an emailed link (docs/password-reset.md,
 * whose failure list the numbers cite). The link's token is the same kind as
 * an invitation's: 256 random bits, stored only as its SHA-256.
 */
final readonly class PasswordResets
{
    public const string EXPIRES_IN = '+1 hour';

    public const int PER_HOUR = 3;

    public function __construct(
        private PasswordResetRepository $resets,
        private UserRepository $users,
        private IdentityPort $identity,
        private ClockPort $clock,
        private QueuePort $queue,
        private MailSettings $mail,
    ) {
    }

    /**
     * Asks for a reset. Says nothing about whether the address has an
     * account (1.): an address with none, a turned-off or unverified account
     * (5.), or a fourth request in the hour (4.) are all quietly nothing.
     *
     * @throws InvalidReset when the address is not one
     */
    public function request(mixed $email): void
    {
        $address = is_string($email) ? strtolower(trim($email)) : '';

        if (strlen($address) > 249 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidReset::because('Give a valid email address.');
        }

        $userId = $this->users->usableIdByEmail($address);
        $now = $this->clock->now();

        if ($userId === null || $this->resets->countSince($userId, $now->modify('-1 hour')) >= self::PER_HOUR) {
            return;
        }

        $this->resets->transactional(function () use ($userId, $now): void {
            $id = $this->resets->insert($userId, $now, $now->modify(self::EXPIRES_IN));
            // Only the newest request's link works (2.).
            $this->resets->closeOpenFor($userId, $now, $id);
            $this->queue->enqueue(PasswordResetMail::of($id));
        });
    }

    /**
     * A link for an open request, for its email: `$deliver` sends it, and the
     * link is kept only once that has worked (9.).
     *
     * @param callable(string,string):void $deliver given the address and the token
     *
     * @throws ResetNotFound
     */
    public function issueLink(int $id, callable $deliver): void
    {
        $this->resets->transactional(function () use ($id, $deliver): void {
            $reset = $this->openReset($this->resets->find($id, true));
            $email = $this->users->findById($reset->userId)?->email ?? throw ResetNotFound::link();
            [$token, $hash] = InvitationToken::issue();

            $deliver($email, $token);
            $this->resets->setTokenHash($id, $hash);
        });
    }

    /**
     * The address a link is for, while it can be used (2.).
     *
     * @throws ResetNotFound
     */
    public function open(string $token): string
    {
        $reset = $this->openReset($this->byToken($token, false));

        return $this->users->findById($reset->userId)?->email ?? throw ResetNotFound::link();
    }

    /**
     * Sets the new password, closes every open link of the account, and
     * queues the notice that it changed (6., 7., 8.). Under the request's row
     * lock, so two uses at once take turns (10.).
     *
     * @throws InvalidReset|ResetNotFound
     */
    public function reset(string $token, mixed $password): void
    {
        if (!is_string($password) || strlen($password) < NewAccount::PASSWORD_MIN || strlen($password) > NewAccount::PASSWORD_MAX) {
            throw InvalidReset::because('Choose a password of at least ' . NewAccount::PASSWORD_MIN . ' characters.');
        }

        $this->resets->transactional(function () use ($token, $password): void {
            $reset = $this->openReset($this->byToken($token, true));
            $email = $this->users->findById($reset->userId)?->email ?? throw ResetNotFound::link();
            $now = $this->clock->now();

            $this->identity->changePassword($reset->userId, $password);
            $this->resets->closeOpenFor($reset->userId, $now);
            $this->queue->enqueue(SendMail::of(PasswordResetEmail::changed($email, $this->mail)));
        });
    }

    private function byToken(string $token, bool $forUpdate): ?PasswordReset
    {
        $hash = InvitationToken::hash($token);

        return $hash === null ? null : $this->resets->findByTokenHash($hash, $forUpdate);
    }

    private function openReset(?PasswordReset $reset): PasswordReset
    {
        if ($reset === null || !$reset->opensAt(PasswordResetRepository::time($this->clock->now()))) {
            throw ResetNotFound::link();
        }

        return $reset;
    }
}
