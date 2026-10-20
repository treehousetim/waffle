<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Status\HasTransitions;

/**
 * Whether a site's key opens anything (docs/forms.md). Also the `types` group
 * `site_status`.
 *
 *   active ⇄ disabled
 */
enum SiteStatus: string
{
    use HasTransitions;

    case Active = 'active';
    case Disabled = 'disabled';

    public static function machineName(): string
    {
        return 'site';
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::Disabled],
            self::Disabled => [self::Active],
        };
    }
}
