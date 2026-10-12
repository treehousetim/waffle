<?php

declare(strict_types=1);

/*
 * The release flow's paperwork (AGENTS.md, "Release flow"): the version in
 * src/frontend/package.json is the release, and it must have its own section
 * in CHANGELOG.md before it can be tagged. Without this the changelog quietly
 * stops being updated and names an old version as current for months.
 */

function projectFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
}

it('has a changelog section for the version being released', function (): void {
    $version = json_decode(projectFile('src/frontend/package.json'), true)['version'];

    expect(projectFile('CHANGELOG.md'))->toMatch('/^## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}$/m');
});

it('keeps an Unreleased section at the top for the next one', function (): void {
    $changelog = projectFile('CHANGELOG.md');

    preg_match_all('/^## \[([^\]]+)\]/m', $changelog, $sections);

    expect($sections[1][0] ?? null)->toBe('Unreleased');
});

it('records the same version in the lock file as in package.json', function (): void {
    $package = json_decode(projectFile('src/frontend/package.json'), true);
    $lock = json_decode(projectFile('src/frontend/package-lock.json'), true);

    expect($lock['version'])->toBe($package['version'])
        ->and($lock['packages']['']['version'])->toBe($package['version']);
});
