<?php

declare(strict_types=1);

namespace App\Domain\Settings;

/**
 * What kind of value a setting holds, and what a valid one looks like.
 *
 * A vocabulary in both places, as every type and status is: these case values
 * are the source of truth, and the `types` group `setting_type` holds the same
 * names as rows that `settings_type_id` is a foreign key into.
 * tests/Unit/Domain/VocabularyTest.php fails if the two disagree.
 *
 * Values are stored as text. normalise() turns whatever the API received into
 * the one text form each type is stored in, or refuses it.
 */
enum SettingType: string
{
    case Text = 'string';
    case Integer = 'int';
    case Boolean = 'bool';
    case Select = 'select';

    /** The longest text a setting stores. The column is TEXT; this is policy. */
    public const int MAX_LENGTH = 1000;

    /**
     * @param list<string> $options the allowed values, for a select
     *
     * @throws InvalidSetting
     */
    public function normalise(mixed $raw, array $options = []): string
    {
        if (!is_scalar($raw)) {
            throw InvalidSetting::because('must be a single value');
        }

        return match ($this) {
            self::Text => self::text($raw),
            self::Integer => self::integer($raw),
            self::Boolean => self::boolean($raw),
            self::Select => self::select($raw, $options),
        };
    }

    private static function text(bool|float|int|string $raw): string
    {
        $value = trim((string) $raw);

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw InvalidSetting::because('must be at most ' . self::MAX_LENGTH . ' characters');
        }

        return $value;
    }

    private static function integer(bool|float|int|string $raw): string
    {
        $value = is_string($raw) ? trim($raw) : $raw;

        if (is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw InvalidSetting::because('must be a whole number');
        }

        return (string) (int) $value;
    }

    private static function boolean(bool|float|int|string $raw): string
    {
        $value = is_bool($raw) ? $raw : filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            throw InvalidSetting::because('must be true or false');
        }

        return $value ? '1' : '0';
    }

    /**
     * @param list<string> $options
     */
    private static function select(bool|float|int|string $raw, array $options): string
    {
        $value = trim((string) $raw);

        if (!in_array($value, $options, true)) {
            throw InvalidSetting::because('must be one of: ' . implode(', ', $options));
        }

        return $value;
    }
}
