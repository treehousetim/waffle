<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * The Inbox's Email form: a message sent to the domain (docs/mail.md). The app
 * files these itself; no site may send one (15.), so nothing is ever parsed
 * from a site's input.
 */
final class EmailForm implements FormFields
{
    public function parse(array $input): array
    {
        throw InvalidForm::because('This form is not sent by a site.');
    }
}
