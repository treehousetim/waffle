<?php

declare(strict_types=1);

/*
 * The release paperwork (docs/releasing.md). VERSION is the one place the
 * version is written, and the last release must have its changelog section.
 * Without this the changelog quietly stops being updated and names an old
 * version as current for months.
 */

function projectFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
}

function projectVersion(): string
{
    return trim(projectFile('VERSION'));
}

it('holds one version, as an app\'s release number or the framework\'s semver', function (): void {
    expect(projectFile('VERSION'))->toMatch('/\A(?:0|[1-9]\d*)(?:\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*))?\n\z/');
});

it('has a changelog section for the version last released', function (): void {
    $version = projectVersion();

    // 0 is an app that has never been released: there is no section yet.
    if ($version === '0') {
        expect(projectFile('CHANGELOG.md'))->not->toMatch('/^## \[\d/m');

        return;
    }

    expect(projectFile('CHANGELOG.md'))->toMatch('/^## \[' . preg_quote($version, '/') . '\] - \d{4}-\d{2}-\d{2}$/m');
});

it('keeps an Unreleased section at the top for the next one', function (): void {
    preg_match_all('/^## \[([^\]]+)\]/m', projectFile('CHANGELOG.md'), $sections);

    expect($sections[1][0] ?? null)->toBe('Unreleased');
});

it('writes the version nowhere else', function (): void {
    $package = json_decode(projectFile('src/frontend/package.json'), true);
    $lock = json_decode(projectFile('src/frontend/package-lock.json'), true);

    // npm insists a version there is semver, and two copies drift.
    expect($package)->not->toHaveKey('version')
        ->and($lock)->not->toHaveKey('version')
        ->and($lock['packages'][''])->not->toHaveKey('version')
        ->and(projectFile('composer.json'))->not->toContain('"version"')
        // The footer reads VERSION at build time.
        ->and(projectFile('src/frontend/vite.config.js'))->toContain("'../../VERSION'");
});

it('deploys only an app\'s release tags, and never from the framework\'s own repository', function (): void {
    $workflow = projectFile('.github/workflows/deploy.yml');

    expect($workflow)->toContain('if [[ ! "$TAG" =~ ^v[1-9][0-9]*$ ]]; then')
        ->and($workflow)->toMatch("/^    if: github\\.repository != 'treehousetim\\/waffle'$/m");
});
