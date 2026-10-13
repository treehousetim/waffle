<?php

declare(strict_types=1);

/*
 * The Waffle Iron module format (docs/modules.md). Waffle ships no modules:
 * this checks the fixture under tests/Support/Fixtures/modules/, so the rules
 * themselves are always exercised, and any module an author drops into
 * modules/ (a directory, a .zip of one, or a single <name>.module.md) to check
 * it before publishing or installing.
 */

const MODULE_SECTIONS = ['Purpose', 'Roles and permissions', 'Data model and migrations', 'Failure list', 'Installation'];
const MODULE_TEST_SECTIONS = ['Desired outcomes', 'How to test', 'Definition of done'];
const MODULE_KEYS = ['name', 'title', 'version', 'summary', 'waffle', 'proven-on', 'author', 'license', 'permissions', 'libraries', 'config'];
const MODULE_FIXTURE = 'fixture-notes';

/** @return list<string> the directories modules are read from: the fixture's, then modules/ */
function moduleRoots(): array
{
    return [dirname(__DIR__) . '/Support/Fixtures/modules', dirname(__DIR__, 2) . '/modules'];
}

/**
 * Every module, as [name => [module file text, module-test file text]].
 *
 * @return array<string,array{0:string,1:string}>
 */
function waffleModules(): array
{
    $modules = [];

    foreach (moduleRoots() as $root) {
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
    }

    ksort($modules);

    return $modules;
}

/**
 * Every file name in every directory and .zip module, and whether it is a
 * symlink, as [name => [relative path => is a symlink]]. A clone's .git/ is
 * not part of the module (docs/modules.md, "The hash").
 *
 * @return array<string,array<string,bool>>
 */
function moduleEntries(): array
{
    $modules = [];

    foreach (moduleRoots() as $root) {
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $entries = [];
            $files = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    static fn (SplFileInfo $file): bool => $file->getFilename() !== '.git',
                ),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($files as $file) {
                $entries[substr($file->getPathname(), strlen($dir) + 1)] = is_link($file->getPathname());
            }

            $modules[basename($dir)] = $entries;
        }

        foreach (glob($root . '/*.zip') ?: [] as $file) {
            $entries = [];
            $zip = new ZipArchive();
            $zip->open($file);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $zip->getExternalAttributesIndex($i, $system, $attributes);
                $entries[(string) $zip->getNameIndex($i)] = $system === ZipArchive::OPSYS_UNIX
                    && (($attributes >> 16) & 0o170000) === 0o120000;
            }

            $zip->close();
            $modules[basename($file, '.zip')] = $entries;
        }
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

it('always has the fixture module to check', function (): void {
    expect(waffleModules())->toHaveKey(MODULE_FIXTURE)
        ->and(moduleEntries())->toHaveKey(MODULE_FIXTURE);
});

it('holds no symlinks and only printable ASCII file names, so the hash means one thing', function (string $name, array $entries): void {
    expect($entries)->not->toBe([]);

    foreach ($entries as $path => $isLink) {
        expect($isLink)->toBeFalse("{$name}: {$path} is a symlink")
            ->and(preg_match('/\A[\x20-\x7E]+\z/', $path))->toBe(1, "{$name}: a file name that is not printable ASCII");
    }
})->with(fn (): array => array_map(static fn (string $name, array $entries): array => [$name, $entries], array_keys(moduleEntries()), moduleEntries()));

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
