<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Status\HasTransitions;

/**
 * Where a record stands (docs/content.md). Also the `types` group
 * `publish_status`; VocabularyTest keeps the two in step.
 *
 *   draft → published
 *   published → retired
 *   retired → published
 *
 * Publishing a published record is not a move: it writes a new revision.
 */
enum PublishStatus: string
{
    use HasTransitions;

    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';

    public static function machineName(): string
    {
        return 'record';
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft, self::Retired => [self::Published],
            self::Published => [self::Retired],
        };
    }
}
