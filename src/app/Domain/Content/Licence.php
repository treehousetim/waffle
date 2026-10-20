<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * Whose an image is (docs/content.md, "Media"). Also the `types` group
 * `licence`; VocabularyTest keeps the two in step.
 */
enum Licence: string
{
    /** The organisation's own picture. */
    case Own = 'own';
    /** Someone else's, used with their permission: credited. */
    case Permission = 'permission';
    /** Under a licence that asks for credit (Creative Commons, a stock library): credited. */
    case Licensed = 'licensed';

    public function needsCredit(): bool
    {
        return $this !== self::Own;
    }
}
