<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/** One form's fields and their rules (docs/forms.md): what a submission keeps. */
interface FormFields
{
    /**
     * The fields from a submission, checked; nothing else is kept (6.).
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     *
     * @throws InvalidForm with a message per field it refuses
     */
    public function parse(array $input): array;
}
