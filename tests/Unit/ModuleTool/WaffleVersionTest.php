<?php

declare(strict_types=1);

use ModuleTool\Refusal;
use ModuleTool\WaffleVersion;
use Tests\Support\ModuleFixtures;

/* Which Waffle version an app is on (docs/module-command.md, 32). */

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

it('reads the version an app was started from', function (): void {
    expect(WaffleVersion::of(ModuleFixtures::app($this->base, '0.2.3')))->toBe('0.2.3');
});

it("reads Waffle's own semver VERSION", function (): void {
    file_put_contents($this->base . '/VERSION', "0.4.0\n");

    expect(WaffleVersion::of($this->base))->toBe('0.4.0');
});

it('refuses when it cannot tell (32)', function (): void {
    file_put_contents($this->base . '/VERSION', "3\n");
    file_put_contents($this->base . '/CHANGELOG.md', "Started from [treehousetim/waffle](https://github.com/treehousetim/waffle).\n");

    expect(fn () => WaffleVersion::of($this->base))->toThrow(Refusal::class, 'cannot tell which Waffle version')
        ->and(fn () => WaffleVersion::of($this->base . '/nowhere'))->toThrow(Refusal::class, '(32)');
});

it('agrees with the line the rename writes', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 3) . '/tools/rename-project.sh'))
        ->toContain('ORIGIN_LINE="Started from [treehousetim/waffle v${STARTED_FROM}]');
});
