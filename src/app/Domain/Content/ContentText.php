<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * The rules for a record's text (docs/content.md, 10.): trimmed, valid UTF-8,
 * no control characters, within its length. Optional fields come back null
 * when empty.
 */
final class ContentText
{
    public const int TITLE_MAX = 150;

    public const int SUMMARY_MAX = 300;

    public const int SEO_TITLE_MAX = 60;

    public const int SEO_DESCRIPTION_MAX = 160;

    /** @throws InvalidContent */
    public static function title(mixed $raw): string
    {
        $title = self::line($raw, 'title', self::TITLE_MAX);

        if ($title === '') {
            throw InvalidContent::because('The title cannot be empty.');
        }

        return $title;
    }

    /** @throws InvalidContent */
    public static function summary(mixed $raw): string
    {
        return self::line($raw ?? '', 'summary', self::SUMMARY_MAX);
    }

    /** @throws InvalidContent */
    public static function seoTitle(mixed $raw): ?string
    {
        return self::optional($raw, 'SEO title', self::SEO_TITLE_MAX);
    }

    /** @throws InvalidContent */
    public static function seoDescription(mixed $raw): ?string
    {
        return self::optional($raw, 'SEO description', self::SEO_DESCRIPTION_MAX);
    }

    /**
     * Text that may run over several lines, such as a body: line breaks and
     * tabs kept, other control characters refused.
     *
     * @throws InvalidContent
     */
    public static function multiline(mixed $raw, string $what, int $max): string
    {
        $text = self::clean($raw ?? '', '/[\x00-\x08\x0B-\x1F\x7F]/u', $what);

        if (mb_strlen($text) > $max) {
            throw InvalidContent::because('The ' . $what . ' can be at most ' . number_format($max) . ' characters.');
        }

        return $text;
    }

    /**
     * One line of optional text a record type keeps in its own field: null
     * when empty.
     *
     * @throws InvalidContent
     */
    public static function optionalLine(mixed $raw, string $what, int $max): ?string
    {
        return self::optional($raw, $what, $max);
    }

    /**
     * An optional https:// address: null when empty. Printable ASCII only, as
     * the site's build checks it (a pasted non-breaking space would pass a
     * byte-wise check here and fail the site's), so whatever the app accepts
     * the site can build.
     *
     * @throws InvalidContent
     */
    public static function optionalHttpsUrl(mixed $raw, string $what, int $max): ?string
    {
        $url = self::optional($raw, $what, $max);

        if ($url !== null && (preg_match('~^https://[^\s/?#]+\.[^\s/?#]+(?:[/?#]\S*)?$~i', $url) !== 1 || preg_match('/[^\x21-\x7E]/', $url) === 1)) {
            throw InvalidContent::because("The {$what} must be an https:// address.");
        }

        return $url;
    }

    /**
     * An image from the media library that a record type names in its own
     * field, such as a cover: its id, or null when empty. Whether it exists
     * is the media library's to say, when the record is saved.
     *
     * @throws InvalidContent
     */
    public static function optionalImage(mixed $raw, string $what): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if ((is_int($raw) && $raw > 0) || (is_string($raw) && preg_match('/^[1-9][0-9]{0,9}$/', $raw) === 1)) {
            return (int) $raw;
        }

        throw InvalidContent::because("The {$what} must be an image from the media library.");
    }

    /** @throws InvalidContent */
    private static function optional(mixed $raw, string $what, int $max): ?string
    {
        $text = self::line($raw ?? '', $what, $max);

        return $text === '' ? null : $text;
    }

    /** @throws InvalidContent */
    private static function line(mixed $raw, string $what, int $max): string
    {
        $text = self::clean($raw, '/[\x00-\x1F\x7F]/u', $what);

        if (mb_strlen($text) > $max) {
            throw InvalidContent::because('The ' . $what . ' can be at most ' . $max . ' characters.');
        }

        return $text;
    }

    /** @throws InvalidContent */
    private static function clean(mixed $raw, string $control, string $what): string
    {
        if (!is_string($raw)) {
            throw InvalidContent::because('Send the ' . $what . ' as text.');
        }

        $text = str_replace("\r\n", "\n", trim($raw));

        if (!mb_check_encoding($text, 'UTF-8')) {
            throw InvalidContent::because('The ' . $what . ' is not valid UTF-8.');
        }

        if (preg_match($control, $text) === 1) {
            throw InvalidContent::because('The ' . $what . ' holds characters that cannot be shown.');
        }

        return $text;
    }
}
