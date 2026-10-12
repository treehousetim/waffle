<?php

declare(strict_types=1);

use App\Models\TypeRepository;
use Tests\Support\Factory;
use Tests\TestCase;

beforeEach(function (): void {
    $this->types = new TypeRepository(TestCase::pdo());
});

it('resolves a name to its id and back, with its label', function (): void {
    $id = $this->types->id('setting_type', 'bool');

    expect($id)->toBeInt()
        ->and($this->types->name($id))->toBe('bool')
        ->and($this->types->label($id))->toBe('On or off');
});

it('lists a group in its own order, for a select', function (): void {
    expect($this->types->options('setting_type'))->toBe([
        'string' => 'Text',
        'int' => 'Whole number',
        'bool' => 'On or off',
        'select' => 'One of a list',
    ]);
});

it('throws on a name nobody added, because that is enum and migration drifting apart', function (): void {
    expect(fn () => $this->types->id('setting_type', 'colour'))
        ->toThrow(RuntimeException::class, "Unknown type 'colour' in group 'setting_type'. Add it in a migration before using it.");
});

it('throws on an id that is not there', function (string $method): void {
    expect(fn () => $this->types->{$method}(999_999_999))->toThrow(RuntimeException::class, 'Unknown type id 999999999.');
})->with(['name', 'label']);

it('reads the table once, until told to forget it', function (): void {
    $this->types->id('setting_type', 'bool');
    $added = Factory::insert('types', [
        'types_group' => 'setting_type', 'types_name' => 'colour', 'types_label' => 'Colour', 'types_sort' => 99,
    ]);

    // Memoised: the new row is not seen yet.
    expect(fn () => $this->types->id('setting_type', 'colour'))->toThrow(RuntimeException::class);

    $this->types->forget();

    expect($this->types->id('setting_type', 'colour'))->toBe($added);
});
