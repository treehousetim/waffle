<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * A record's slug (docs/content.md, 8.): lowercase letters, digits and single
 * dashes, at most 100 characters. It is the last part of the record's URL on
 * the site, so once given it is kept forever.
 */
final class Slug
{
    public const int MAX = 100;

    private const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @throws InvalidContent */
    public static function check(mixed $raw): string
    {
        $slug = is_string($raw) ? trim($raw) : '';

        if ($slug === '') {
            throw InvalidContent::because('The slug cannot be empty.');
        }

        if (strlen($slug) > self::MAX) {
            throw InvalidContent::because('The slug can be at most ' . self::MAX . ' characters.');
        }

        if (preg_match(self::PATTERN, $slug) !== 1) {
            throw InvalidContent::because('The slug can hold only lowercase letters, digits and single dashes between them.');
        }

        return $slug;
    }

    /**
     * A slug made from a title: accents dropped, everything else that is not
     * a letter or digit turned into dashes. A title with neither gives
     * "untitled".
     */
    public static function suggest(string $title): string
    {
        $ascii = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', self::ascii($title)));
        $slug = trim(substr(trim($ascii, '-'), 0, self::MAX - 10), '-');

        return $slug === '' ? 'untitled' : $slug;
    }

    /** Accents dropped by the C library's transliteration; what it cannot spell becomes "?". */
    private static function ascii(string $text): string
    {
        return (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    }
}
