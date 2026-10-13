<?php

declare(strict_types=1);

/*
 * The Waffle Iron module format (docs/modules.md): every module in modules/,
 * as a directory, a .zip of one, or a single <name>.module.md, holds at least
 * what the specification requires.
 */

const MODULE_SECTIONS = ['Purpose', 'Roles and permissions', 'Data model and migrations', 'Failure list', 'Installation'];
const MODULE_TEST_SECTIONS = ['Desired outcomes', 'How to test', 'Definition of done'];
const MODULE_KEYS = ['name', 'title', 'version', 'summary', 'waffle', 'proven-on', 'author', 'license', 'permissions', 'libraries', 'config'];

/**
 * Every module, as [name => [module file text, module-test file text]].
 *
 * @return array<string,array{0:string,1:string}>
 */
function waffleModules(): array
{
    $root = dirname(__DIR__, 2) . '/modules';
    $modules = [];

    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $modules[basename($dir)] = [
            (string) @file_get_contents($dir . '/MODULE.md'),
            (string) @file_get_contents($dir . '/MODULE-TEST.md'),
        ];
    }

    foreach (glob($root . '/*.module.md') ?: [] as $file) {
        $text = (string) file_get_contents($file);
        $modules[basename($file, '.module.md')] = [$text, $text];
    }

    foreach (glob($root . '/*.zip') ?: [] as $file) {
        $zip = new ZipArchive();
        $name = basename($file, '.zip');
        $zip->open($file);
        $modules[$name] = [
            (string) $zip->getFromName($name . '/MODULE.md'),
            (string) $zip->getFromName($name . '/MODULE-TEST.md'),
        ];
        $zip->close();
    }

    ksort($modules);

    return $modules;
}

/** @return array<string,string> */
function moduleFrontMatter(string $text): array
{
    if (!preg_match('/\A---\n(.*?)\n---\n/s', $text, $match)) {
        return [];
    }

    $keys = [];

    foreach (explode("\n", $match[1]) as $line) {
        if (preg_match('/^([a-z-]+):\s*(.*)$/', $line, $pair)) {
            $keys[$pair[1]] = trim($pair[2]);
        }
    }

    return $keys;
}

/** The text of one `## ` section, up to the next. */
function moduleSection(string $text, string $heading): string
{
    return preg_match('/^## ' . preg_quote($heading, '/') . '\s*\n(.*?)(?=^## |\z)/ms', $text, $match) ? $match[1] : '';
}

/** @return list<int> the numbers of a numbered list ("1. ...") */
function moduleNumbers(string $section): array
{
    preg_match_all('/^(\d+)\. /m', $section, $numbers);

    return array_map('intval', $numbers[1]);
}

it('ships at least one module', function (): void {
    expect(waffleModules())->not->toBe([]);
});

it('gives every module its required front matter', function (string $name, array $files): void {
    $keys = moduleFrontMatter($files[0]);

    expect(array_diff(MODULE_KEYS, array_keys($keys)))->toBe([])
        ->and(array_filter($keys, static fn (string $value): bool => $value === ''))->toBe([])
        ->and($keys['name'])->toBe($name)
        ->and($name)->toMatch('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/')
        ->and($keys['version'])->toMatch('/^\d+\.\d+\.\d+$/')
        ->and($keys['proven-on'])->toMatch('/^(none|\d+\.\d+\.\d+(?:, *\d+\.\d+\.\d+)*)$/');

    foreach (array_map('trim', explode(',', $keys['permissions'])) as $permission) {
        expect($permission)->toMatch('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/');
    }
})->with(fn (): array => array_map(static fn (string $name, array $files): array => [$name, $files], array_keys(waffleModules()), waffleModules()));

it('describes the feature with every required section', function (string $name, array $files): void {
    foreach (MODULE_SECTIONS as $heading) {
        expect(trim(moduleSection($files[0], $heading)))->not->toBe('', "{$name}: ## {$heading}");
    }
})->with(fn (): array => array_map(static fn (string $name, array $files): array => [$name, $files], array_keys(waffleModules()), waffleModules()));

it('says what every level may do, and how the data is created', function (string $name, array $files): void {
    $roles = strtolower(moduleSection($files[0], 'Roles and permissions'));
    $data = moduleSection($files[0], 'Data model and migrations');

    foreach (['owner', 'administrator', 'user', 'not signed in'] as $level) {
        expect($roles)->toContain($level);
    }

    expect($data)->toMatch('/CREATE TABLE|extends AbstractMigration/');
})->with(fn (): array => array_map(static fn (string $name, array $files): array => [$name, $files], array_keys(waffleModules()), waffleModules()));

it('says what working means and how to prove it', function (string $name, array $files): void {
    foreach (MODULE_TEST_SECTIONS as $heading) {
        expect(trim(moduleSection($files[1], $heading)))->not->toBe('', "{$name}: ## {$heading}");
    }
})->with(fn (): array => array_map(static fn (string $name, array $files): array => [$name, $files], array_keys(waffleModules()), waffleModules()));

it('covers every failure with at least one desired outcome', function (string $name, array $files): void {
    $failures = moduleNumbers(moduleSection($files[0], 'Failure list'));
    $outcomes = moduleSection($files[1], 'Desired outcomes');
    preg_match_all('/\(([\d, ]+)\)/', $outcomes, $cited);
    $covered = [];

    foreach ($cited[1] as $list) {
        foreach (array_map('intval', explode(',', $list)) as $number) {
            $covered[$number] = true;
        }
    }

    expect($failures)->not->toBe([])
        ->and($failures)->toBe(range(1, count($failures)))
        ->and(moduleNumbers($outcomes))->not->toBe([])
        ->and(array_values(array_diff($failures, array_keys($covered))))->toBe([]);
})->with(fn (): array => array_map(static fn (string $name, array $files): array => [$name, $files], array_keys(waffleModules()), waffleModules()));
