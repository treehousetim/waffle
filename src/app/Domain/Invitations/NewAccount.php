<?php

declare(strict_types=1);

namespace App\Domain\Invitations;

/**
 * What a person gives when they accept an invitation (docs/invitations.md,
 * 9.): a name, and a password of at least 12 characters.
 */
final readonly class NewAccount
{
    public const int NAME_MAX = 100;

    public const int PASSWORD_MIN = 12;

    /** Long enough for any passphrase, short enough not to be a hashing burden. */
    public const int PASSWORD_MAX = 1000;

    private function __construct(public string $name, public string $password)
    {
    }

    /** @throws InvalidInvitation */
    public static function parse(mixed $name, mixed $password): self
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || mb_strlen($name) > self::NAME_MAX || !mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw InvalidInvitation::because('Give your name, in at most ' . self::NAME_MAX . ' characters.');
        }

        if (!is_string($password) || strlen($password) < self::PASSWORD_MIN || strlen($password) > self::PASSWORD_MAX) {
            throw InvalidInvitation::because('Choose a password of at least ' . self::PASSWORD_MIN . ' characters.');
        }

        return new self($name, $password);
    }
}
