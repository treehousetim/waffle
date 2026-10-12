<?php

declare(strict_types=1);

use App\Domain\Settings\SettingType;
use App\Models\SettingRepository;
use App\Models\TypeRepository;
use Tests\TestCase;

beforeEach(function (): void {
    $this->settings = new SettingRepository(TestCase::pdo(), new TypeRepository(TestCase::pdo()));
});

it('lists every setting by group, then by each group\'s own order', function (): void {
    expect(array_map(static fn ($s): string => $s->group . '/' . $s->key, $this->settings->all()))->toBe([
        'display/page_size',
        'display/date_style',
        'general/app_name',
        'general/display_timezone',
        'general/schema_owner',
    ]);
});

it('reads a setting with its type and options', function (): void {
    $setting = $this->settings->find('date_style');

    expect($setting->type)->toBe(SettingType::Select)
        ->and($setting->options)->toBe(['relative', 'absolute'])
        ->and($setting->editable)->toBeTrue()
        ->and($setting->sort)->toBe(20)
        ->and($setting->updatedAt)->toBeNull();
});

it('reads a setting with no options as an empty list', function (): void {
    expect($this->settings->find('page_size')->options)->toBe([])
        ->and($this->settings->find('page_size')->type)->toBe(SettingType::Integer);
});

it('finds nothing for a key that does not exist', function (): void {
    expect($this->settings->find('nope'))->toBeNull();
});

it('stores a value and when it changed', function (): void {
    $this->settings->update('page_size', '40');

    $setting = $this->settings->find('page_size');

    expect($setting->value)->toBe('40')
        ->and($setting->updatedAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('reads a NULL value as empty text', function (): void {
    TestCase::pdo()->exec("UPDATE settings SET settings_value = NULL WHERE settings_key = 'app_name'");

    expect($this->settings->find('app_name')->value)->toBe('');
});
