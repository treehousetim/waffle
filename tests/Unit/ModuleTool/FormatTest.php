<?php

declare(strict_types=1);

use ModuleTool\Format;

/*
 * The module format (docs/modules.md), shared by ModulesTest and the module
 * command (docs/module-command.md, 6 and 17).
 */

function formatFixture(): array
{
    $dir = dirname(__DIR__, 2) . '/Support/Fixtures/modules/fixture-notes';

    return [(string) file_get_contents($dir . '/MODULE.md'), (string) file_get_contents($dir . '/MODULE-TEST.md')];
}

it('passes the fixture module', function (): void {
    [$module, $test] = formatFixture();

    expect(Format::problems('fixture-notes', $module, $test))->toBe([]);
});

it('names each thing wrong with a module (17)', function (string $from, string $to, string $problem, string $file = 'module'): void {
    [$module, $test] = formatFixture();

    if ($file === 'module') {
        $module = str_replace($from, $to, $module);
    } else {
        $test = str_replace($from, $to, $test);
    }

    expect(Format::problems('fixture-notes', $module, $test))->toContain($problem);
})->with([
    'a missing key' => ["author: treehousetim\n", '', "MODULE.md: front matter has no 'author'"],
    'an empty key' => ['author: treehousetim', 'author:', "MODULE.md: front matter has no 'author'"],
    'another name' => ['name: fixture-notes', 'name: other-notes', "MODULE.md: name 'other-notes' does not match where it is, 'fixture-notes'"],
    'a version that is not semver' => ['version: 1.0.0', 'version: 1.0', "MODULE.md: version '1.0' is not X.Y.Z"],
    'proven-on that is not a list of versions' => ['proven-on: none', 'proven-on: yesterday', "MODULE.md: proven-on 'yesterday' is not 'none' or a list of X.Y.Z"],
    'a constraint outside the grammar (6)' => ['waffle: >=0.1 <1.0', 'waffle: ^0.1', "MODULE.md: the waffle constraint '^0.1' is not one this command can read: space-separated comparisons such as '>=0.1 <1.0' (operators >=, >, <=, <, =), with '||' between alternatives."],
    'a permission that is not kebab-case' => ['permissions: fixture-notes-view', 'permissions: Fixture_View', "MODULE.md: permission 'Fixture_View' is not lowercase words joined by hyphens"],
    'a missing section' => ['## Installation', '## Setup', "MODULE.md: no '## Installation' section"],
    'a level left out' => ['- Anyone not signed in gets `401`.', '', "MODULE.md: Roles and permissions does not say what 'not signed in' may do"],
    'no migration' => ['CREATE TABLE', 'MAKE TABLE', 'MODULE.md: Data model and migrations has no CREATE TABLE or migration class'],
    'no failures' => ["1. Someone not signed in reads a note.\n2. A note longer than 255 characters is cut short instead of refused.", 'Nothing can fail.', 'MODULE.md: the Failure list has no numbered items'],
    'failures out of order' => ['2. A note longer', '3. A note longer', 'MODULE.md: the Failure list is not numbered 1, 2, 3 in order'],
    'a missing test section' => ['## How to test', '## Testing', "MODULE-TEST.md: no '## How to test' section", 'test'],
    'no outcomes' => ["1. Signed out, reading notes answers `401`. (1)\n2. A note of 256 characters is refused with `422`. (2)", 'It works.', 'MODULE-TEST.md: Desired outcomes has no numbered items', 'test'],
    'a failure nobody covers' => ['refused with `422`. (2)', 'refused with `422`.', 'MODULE-TEST.md: no desired outcome covers failure 2', 'test'],
]);

it('refuses a name that is not kebab-case', function (): void {
    [$module, $test] = formatFixture();

    expect(Format::problems('Fixture_Notes', $module, $test))->toContain("'Fixture_Notes' is not a module name: lowercase words joined by hyphens");
});

it('finds no front matter without its fences', function (): void {
    expect(Format::frontMatter("name: x\n"))->toBe([]);
});

it('reads a comma-separated value, none being empty', function (): void {
    expect(Format::list('a, b,, c'))->toBe(['a', 'b', 'c'])
        ->and(Format::list('none'))->toBe([])
        ->and(Format::list(''))->toBe([]);
});
