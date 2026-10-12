<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use DomainException;

/**
 * A value a setting cannot hold, or a setting that cannot be changed.
 *
 * The message is written for the person who typed the value: the controller
 * returns it as the API error, prefixed with the setting's label.
 */
final class InvalidSetting extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
