<?php

declare(strict_types=1);

/*
 * The settings slice through the router (docs/settings.md). Who may read and
 * who may write is walked for every level by AccessGridTest; this is what
 * reading and writing do.
 */

function settingNamed(array $settings, string $key): array
{
    foreach ($settings as $setting) {
        if ($setting['key'] === $key) {
            return $setting;
        }
    }

    throw new RuntimeException("no setting {$key}");
}

it('lists every setting with what the page needs to render it', function (): void {
    $this->asAdministrator();

    $response = $this->get('/api/settings');
    $settings = $this->json($response)['data']['settings'];

    expect($response->getStatusCode())->toBe(200)
        ->and(array_column($settings, 'key'))->toBe(['page_size', 'date_style', 'app_name', 'display_timezone', 'schema_owner'])
        ->and(settingNamed($settings, 'date_style'))->toBe([
            'key' => 'date_style',
            'value' => 'relative',
            'label' => 'Date style',
            'description' => 'How dates are written in lists.',
            'type' => 'select',
            'options' => ['relative', 'absolute'],
            'group' => 'display',
            'editable' => true,
            'updated_at' => null,
        ]);
});

it('saves several values at once, normalised to how each type is stored', function (): void {
    $this->asOwner();

    $response = $this->put('/api/settings', ['values' => ['page_size' => ' 50 ', 'date_style' => 'absolute', 'app_name' => '  Renamed  ']]);
    $settings = $this->json($response)['data']['settings'];

    expect($response->getStatusCode())->toBe(200)
        ->and(settingNamed($settings, 'page_size')['value'])->toBe('50')
        ->and(settingNamed($settings, 'date_style')['value'])->toBe('absolute')
        ->and(settingNamed($settings, 'app_name')['value'])->toBe('Renamed')
        ->and(settingNamed($settings, 'page_size')['updated_at'])->not->toBeNull();
});

it('changes nothing when any one value is refused', function (array $values, string $error): void {
    $this->asOwner();

    $response = $this->put('/api/settings', ['values' => $values]);
    $after = $this->json($this->get('/api/settings'))['data']['settings'];

    expect($response->getStatusCode())->toBe(422)
        ->and($this->json($response)['error'])->toBe($error)
        ->and(settingNamed($after, 'page_size')['value'])->toBe('25');
})->with([
    'not a number' => [['page_size' => 'lots'], 'Rows per page must be a whole number.'],
    'not an option' => [['page_size' => '50', 'date_style' => 'sideways'], 'Date style must be one of: relative, absolute.'],
    'not editable' => [['page_size' => '50', 'schema_owner' => 'x'], 'Schema owner cannot be changed here.'],
    'unknown key' => [['page_size' => '50', 'nope' => 'x'], "There is no setting called 'nope'."],
    'a list' => [['page_size' => ['50']], 'Rows per page must be a single value.'],
]);

it('asks for the values in the shape it understands', function (array $body): void {
    $this->asOwner();

    $response = $this->put('/api/settings', $body);

    expect($response->getStatusCode())->toBe(422)
        ->and($this->json($response)['error'])->toBe('Send the settings to change as `values`: {key: value}.');
})->with([
    'no values' => [['page_size' => '50']],
    'empty values' => [['values' => [], 'other' => 1]],
    'not a map' => [['values' => 'page_size=50']],
]);
