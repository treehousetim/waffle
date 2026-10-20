<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Status\HasTransitions;

/**
 * Where a form submission stands (docs/forms.md). Also the `types` group
 * `submission_status`; VocabularyTest keeps the two in step.
 *
 *   new → handled | spam
 */
enum SubmissionStatus: string
{
    use HasTransitions;

    case New = 'new';
    case Handled = 'handled';
    case Spam = 'spam';

    public static function machineName(): string
    {
        return 'submission';
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Handled, self::Spam],
            self::Handled, self::Spam => [],
        };
    }
}
