<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use InvalidArgumentException;

/**
 * A submission, or a site, the rules refuse. The message is for the person;
 * `fields` names each field's problem, for the site to show beside it.
 */
final class InvalidForm extends InvalidArgumentException
{
    /** @var array<string,string> */
    public array $fields = [];

    /** @param array<string,string> $fields */
    public static function because(string $message, array $fields = []): self
    {
        $e = new self($message);
        $e->fields = $fields;

        return $e;
    }
}
