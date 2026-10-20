<?php

declare(strict_types=1);

namespace App\Domain\Forms;

/**
 * The rules for what visitors type into forms (docs/forms.md, 6.): text,
 * valid UTF-8, no control characters, within its length, not empty.
 */
final class FormText
{
    /** @throws InvalidForm */
    public static function line(mixed $raw, int $max): string
    {
        return self::text($raw, $max, '/[\x00-\x1F\x7F]/u');
    }

    /** @throws InvalidForm */
    public static function paragraphs(mixed $raw, int $max): string
    {
        return self::text($raw, $max, '/[\x00-\x08\x0B-\x1F\x7F]/u');
    }

    /** @throws InvalidForm */
    public static function email(mixed $raw): string
    {
        $email = self::line($raw, 254);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidForm::because('Please give an email address we can reply to.');
        }

        return $email;
    }

    /** @throws InvalidForm */
    private static function text(mixed $raw, int $max, string $control): string
    {
        $text = is_string($raw) ? str_replace("\r\n", "\n", trim($raw)) : '';

        if ($text === '') {
            throw InvalidForm::because('Please fill this in.');
        }

        if (!mb_check_encoding($text, 'UTF-8') || preg_match($control, $text) === 1) {
            throw InvalidForm::because('This holds characters that cannot be kept.');
        }

        if (mb_strlen($text) > $max) {
            throw InvalidForm::because("This can be at most {$max} characters.");
        }

        return $text;
    }
}
