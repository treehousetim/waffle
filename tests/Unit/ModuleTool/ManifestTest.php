<?php

declare(strict_types=1);

use ModuleTool\Manifest;
use ModuleTool\Refusal;
use Tests\Support\ModuleFixtures;

/* modules.json (docs/modules.md, step 4). */

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
    $this->manifest = new Manifest($this->base . '/modules.json');
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

it('is empty until something is recorded, then holds it sorted', function (): void {
    expect($this->manifest->all())->toBe([])
        ->and($this->manifest->get('notes'))->toBeNull();

    $this->manifest->put('zebra', ['version' => '1.0.0']);
    $this->manifest->put('notes', ['version' => '2.0.0']);

    expect(array_keys($this->manifest->all()))->toBe(['notes', 'zebra'])
        ->and($this->manifest->get('notes'))->toBe(['version' => '2.0.0'])
        ->and(file_get_contents($this->base . '/modules.json'))->toEndWith("}\n");
});

it('refuses a record it cannot read', function (): void {
    file_put_contents($this->base . '/modules.json', '{"short-links": {}}');

    expect(fn () => $this->manifest->all())->toThrow(Refusal::class, 'modules.json is not a module record');
});
