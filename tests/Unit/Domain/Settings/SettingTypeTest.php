<?php

declare(strict_types=1);

use App\Domain\Settings\InvalidSetting;
use App\Domain\Settings\SettingType;

/*
 * Every way a value can be refused, written before the type existed:
 *   - not a single value (a list, an object, null)
 *   - text too long
 *   - a "number" that is not a whole number (letters, decimals, a boolean)
 *   - a "boolean" that is not one
 *   - a choice that is not among the options
 */

it('stores text trimmed', function (): void {
    expect(SettingType::Text->normalise('  hello  '))->toBe('hello')
        ->and(SettingType::Text->normalise(42))->toBe('42');
});

it('refuses text longer than the limit', function (): void {
    expect(SettingType::Text->normalise(str_repeat('é', SettingType::MAX_LENGTH)))->toHaveLength(SettingType::MAX_LENGTH)
        ->and(fn () => SettingType::Text->normalise(str_repeat('a', SettingType::MAX_LENGTH + 1)))
        ->toThrow(InvalidSetting::class, 'must be at most 1000 characters');
});

it('stores a whole number in its plain form', function (mixed $raw, string $stored): void {
    expect(SettingType::Integer->normalise($raw))->toBe($stored);
})->with([
    'a string' => ['42', '42'],
    'padded' => [' 7 ', '7'],
    'negative' => ['-3', '-3'],
    'an int' => [12, '12'],
]);

it('refuses anything that is not a whole number', function (mixed $raw): void {
    expect(fn () => SettingType::Integer->normalise($raw))->toThrow(InvalidSetting::class, 'must be a whole number');
})->with([
    'letters' => ['lots'],
    'a decimal' => ['1.5'],
    'a float' => [1.5],
    'a boolean' => [true],
    'empty' => [''],
]);

it('stores a boolean as 1 or 0', function (mixed $raw, string $stored): void {
    expect(SettingType::Boolean->normalise($raw))->toBe($stored);
})->with([
    'true' => [true, '1'],
    'false' => [false, '0'],
    'the word' => ['true', '1'],
    'off' => ['off', '0'],
    'one' => [1, '1'],
    'zero' => ['0', '0'],
]);

it('refuses a boolean that is not one', function (): void {
    expect(fn () => SettingType::Boolean->normalise('maybe'))->toThrow(InvalidSetting::class, 'must be true or false');
});

it('stores one of the options, and nothing else', function (): void {
    expect(SettingType::Select->normalise(' b ', ['a', 'b']))->toBe('b')
        ->and(fn () => SettingType::Select->normalise('c', ['a', 'b']))
        ->toThrow(InvalidSetting::class, 'must be one of: a, b');
});

it('refuses anything that is not a single value, whatever the type', function (SettingType $type): void {
    foreach ([['a'], null, new stdClass()] as $raw) {
        expect(fn () => $type->normalise($raw, ['a']))->toThrow(InvalidSetting::class, 'must be a single value');
    }
})->with(SettingType::cases());
