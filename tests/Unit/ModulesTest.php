<?php

declare(strict_types=1);

use ModuleTool\Contents;
use ModuleTool\Format;
use ModuleTool\Zip;

/*
 * The Waffle Iron module format (docs/modules.md). Waffle ships no modules:
 * this checks the fixture under tests/Support/Fixtures/modules/, so the rules
 * themselves are always exercised, and any module an author drops into
 * modules/ (a directory, a .zip of one, or a single <name>.module.md) to check
 * it before publishing or installing. The rules are ModuleTool\Format and
 * ModuleTool\Contents, the same ones tools/module.sh applies to an install.
 */

const MODULE_FIXTURE = 'fixture-notes';

/** @return list<string> the directories modules are read from: the fixture's, then modules/ */
function moduleRoots(): array
{
    return [dirname(__DIR__) . '/Support/Fixtures/modules', dirname(__DIR__, 2) . '/modules'];
}

/**
 * Every module as [name => [module file text, module-test file text, directory or null]];
 * a .zip is unpacked into a scratch directory first, through the same checks as an install.
 *
 * @return array<string,array{0:string,1:string,2:?string}>
 */
function waffleModules(): array
{
    $modules = [];
    $read = static fn (string $file): string => is_file($file) ? (string) file_get_contents($file) : '';

    foreach (moduleRoots() as $root) {
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $modules[basename($dir)] = [$read($dir . '/MODULE.md'), $read($dir . '/MODULE-TEST.md'), $dir];
        }

        foreach (glob($root . '/*.module.md') ?: [] as $file) {
            $text = $read($file);
            $modules[basename($file, '.module.md')] = [$text, $text, null];
        }

        foreach (glob($root . '/*.zip') ?: [] as $file) {
            $name = basename($file, '.zip');
            $scratch = sys_get_temp_dir() . '/waffle-modules-' . bin2hex(random_bytes(6));
            mkdir($scratch);
            Zip::extract($file, $scratch, $name);
            $modules[$name] = [$read("{$scratch}/{$name}/MODULE.md"), $read("{$scratch}/{$name}/MODULE-TEST.md"), "{$scratch}/{$name}"];
        }
    }

    ksort($modules);

    return $modules;
}

/** @return list<array{0:string,1:array{0:string,1:string,2:?string}}> */
function moduleCases(): array
{
    $modules = waffleModules();

    return array_map(static fn (string $name, array $files): array => [$name, $files], array_keys($modules), $modules);
}

it('always has the fixture module to check', function (): void {
    expect(waffleModules())->toHaveKey(MODULE_FIXTURE);
});

it('passes the module format: front matter, sections, levels, migrations, and every failure covered', function (string $name, array $files): void {
    expect(Format::problems($name, $files[0], $files[1]))->toBe([]);
})->with(fn (): array => moduleCases());

it('holds no symlinks, odd file names or hidden characters, so the hash and the text mean one thing', function (string $name, array $files): void {
    if ($files[2] === null) {
        // A single file: its name was checked by the glob above; its text is checked here.
        $dir = sys_get_temp_dir() . '/waffle-modules-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/' . $name . '.module.md', $files[0]);
        $files[2] = $dir;
    }

    // A clone dropped into modules/ brings its .git/, which is not the module.
    expect(Contents::scan($files[2], true)->refusals)->toBe([]);
})->with(fn (): array => moduleCases());
