<?php

declare(strict_types=1);

use ModuleTool\Catalogue;
use ModuleTool\Entry;
use ModuleTool\Refusal;
use Tests\Support\ModuleFixtures;

/* Waffle Iron's index.json: steps 2 and 3 of `use` (docs/module-command.md). */

const CAT_COMMIT = '86dc06af62fa365e329e8999c0085fbf5738b013';
const CAT_SHA = 'a41806233df65728caeedc02621521bd6417bb5d35d07b46b00e405cbc3d6e3f';

/** @param array<string,array<string,mixed>> $versions */
function catalogueOf(array $versions): Catalogue
{
    return Catalogue::parse((string) json_encode(['schema' => 1, 'modules' => ['notes' => [
        'title' => 'Notes', 'summary' => 'Notes.', 'author' => 'someone', 'license' => 'MIT', 'versions' => $versions,
    ]]]));
}

it('refuses an index it cannot read (3)', function (string $json): void {
    expect(fn () => Catalogue::parse($json))->toThrow(Refusal::class, 'not a catalogue this command can read (3)');
})->with([
    'not JSON' => ['{'],
    'no schema' => ['{"modules":{}}'],
    'a schema that is not a number' => ['{"schema":"1","modules":{}}'],
    'no modules' => ['{"schema":1}'],
    'an older schema' => ['{"schema":0,"modules":{}}'],
]);

it('refuses a newer schema, saying to update the command (3)', function (): void {
    expect(fn () => Catalogue::parse('{"schema":2,"modules":{}}'))->toThrow(Refusal::class, 'update tools/module.sh from Waffle');
});

it('chooses the newest version that is reviewed, not yanked, and matches (4)', function (): void {
    $catalogue = catalogueOf([
        '1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA),
        '1.1.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA),
        '1.2.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['reviewed-by' => null]),
        '1.3.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['yanked' => 'broken']),
        '2.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['waffle' => '>=1.0']),
        '1.10.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['reviewed-by' => '']),
    ]);

    expect($catalogue->choose('notes', null, '0.1.0')->version)->toBe('1.1.0')
        ->and(array_map(fn (Entry $e): string => $e->version, $catalogue->versions('notes')))->toBe(['2.0.0', '1.10.0', '1.3.0', '1.2.0', '1.1.0', '1.0.0']);
});

it('refuses when no version is eligible, listing each reason (4)', function (): void {
    $catalogue = catalogueOf([
        '1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['reviewed-by' => null]),
        '1.1.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['yanked' => "broken\e[2J"]),
        '2.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['waffle' => '>=1.0']),
    ]);

    expect(fn () => $catalogue->choose('notes', null, '0.1.0'))->toThrow(
        Refusal::class,
        "no version of notes can be installed here (4):\n  2.0.0: written for Waffle >=1.0, and this app is on 0.1.0\n  1.1.0: yanked: broken\\x1B[2J\n  1.0.0: not reviewed yet",
    );
});

it('refuses a named version that is not eligible, naming why (5)', function (array $changes, string $why): void {
    $catalogue = catalogueOf(['1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, $changes)]);

    expect(fn () => $catalogue->choose('notes', '1.0.0', '0.1.0'))->toThrow(Refusal::class, "notes 1.0.0 cannot be installed: {$why} (5)");
})->with([
    'unreviewed' => [['reviewed-by' => null], 'not reviewed yet'],
    'yanked' => [['yanked' => 'harmful'], 'yanked: harmful'],
    'for another Waffle' => [['waffle' => '>=0.2'], 'written for Waffle >=0.2, and this app is on 0.1.0'],
]);

it('takes a named version that is eligible, and refuses one not listed (5)', function (): void {
    $catalogue = catalogueOf(['1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA), '1.1.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA)]);

    expect($catalogue->choose('notes', '1.0.0', '0.1.0')->version)->toBe('1.0.0')
        ->and(fn () => $catalogue->choose('notes', '9.9.9', '0.1.0'))->toThrow(Refusal::class, 'does not list notes 9.9.9 (5)');
});

it('chooses only above the installed version for an upgrade', function (): void {
    $catalogue = catalogueOf(['1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA), '1.1.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA)]);

    expect($catalogue->choose('notes', null, '0.1.0', '1.0.0')->version)->toBe('1.1.0')
        ->and(fn () => $catalogue->choose('notes', null, '0.1.0', '1.1.0'))->toThrow(Refusal::class, '1.1.0: not newer than the installed 1.1.0');
});

it('refuses a malformed entry, naming the field (34, 6, 9)', function (array $changes, string $message): void {
    $catalogue = catalogueOf(['1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, $changes)]);

    expect(fn () => $catalogue->versions('notes'))->toThrow(Refusal::class, $message);
})->with([
    'no constraint' => [['waffle' => ''], "no 'waffle' constraint"],
    'a constraint outside the grammar' => [['waffle' => '^0.1'], "the waffle constraint '^0.1'"],
    'an unknown form' => [['form' => 'tarball'], 'no form this command knows'],
    'no source' => [['source' => 'https://github.example/acme/notes'], 'has no source'],
    'a plain-HTTP repository' => [['source' => ['repository' => 'http://github.example/acme/notes', 'commit' => CAT_COMMIT, 'path' => '.']], 'not https://host/owner/repo'],
    'a repository with credentials' => [['source' => ['repository' => 'https://user:pw@github.example/acme/notes', 'commit' => CAT_COMMIT, 'path' => '.']], 'not https://host/owner/repo'],
    'a repository with an option in it' => [['source' => ['repository' => 'https://github.example/acme/--upload-pack=x', 'commit' => CAT_COMMIT, 'path' => '.']], 'not https://host/owner/repo'],
    'a short commit' => [['source' => ['repository' => 'https://github.example/acme/notes', 'commit' => '86dc06a', 'path' => '.']], 'full 40-character commit'],
    'a path with ..' => [['source' => ['repository' => 'https://github.example/acme/notes', 'commit' => CAT_COMMIT, 'path' => 'modules/../..']], "path that is not '.'"],
    'an absolute path' => [['source' => ['repository' => 'https://github.example/acme/notes', 'commit' => CAT_COMMIT, 'path' => '/etc']], "path that is not '.'"],
    'a path that is an option' => [['source' => ['repository' => 'https://github.example/acme/notes', 'commit' => CAT_COMMIT, 'path' => '--output=x']], "path that is not '.'"],
    'a plain-HTTP url' => [['form' => 'zip', 'source' => ['url' => 'http://example.org/notes.zip']], 'url that is not HTTPS'],
    'a bad sha256' => [['sha256' => 'abc'], '64-character sha256'],
    'proven-on that is not a list' => [['proven-on' => '0.1.0'], 'proven-on that is not a list'],
    'proven-on with a non-version' => [['proven-on' => ['soon']], 'proven-on that is not a list'],
]);

it('refuses a module or version name that is not one (34)', function (): void {
    $catalogue = Catalogue::parse((string) json_encode(['schema' => 1, 'modules' => ['Bad_Name' => ['versions' => ['1.0.0' => []]], 'notes' => ['versions' => ['latest' => []]], 'empty' => ['versions' => []]]]));

    expect(fn () => $catalogue->versions('Bad_Name'))->toThrow(Refusal::class, 'which is not a module name')
        ->and(fn () => $catalogue->versions('notes'))->toThrow(Refusal::class, 'which is not a module name')
        ->and(fn () => $catalogue->versions('empty'))->toThrow(Refusal::class, 'lists no versions of empty')
        ->and(fn () => $catalogue->versions('missing'))->toThrow(Refusal::class, 'no module called missing');
});

it('lists names, what each is about, and finds one version', function (): void {
    $catalogue = Catalogue::parse((string) json_encode(['schema' => 1, 'modules' => [
        'zebra' => ['title' => 'Z', 'versions' => ['1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA)]],
        'notes' => ['title' => 'Notes', 'summary' => 5, 'versions' => ['1.0.0' => ModuleFixtures::entry(CAT_COMMIT, CAT_SHA)]],
    ]]));

    expect($catalogue->names())->toBe(['notes', 'zebra'])
        ->and($catalogue->about('notes'))->toBe(['title' => 'Notes', 'summary' => '', 'author' => '', 'license' => ''])
        ->and($catalogue->find('notes', '1.0.0')?->version)->toBe('1.0.0')
        ->and($catalogue->find('notes', '2.0.0'))->toBeNull()
        ->and($catalogue->find('missing', '1.0.0'))->toBeNull();
});

it('reads a zip or file source, and describes where each is', function (): void {
    $zip = Entry::from('notes', '1.0.0', ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['form' => 'zip', 'source' => ['url' => 'https://example.org/notes.zip', 'extra' => 'dropped']]));
    $dir = Entry::from('notes', '1.0.0', ModuleFixtures::entry(CAT_COMMIT, CAT_SHA, ['source' => ['repository' => 'https://github.example/acme/notes', 'commit' => CAT_COMMIT, 'path' => 'modules/notes', 'extra' => 'dropped']]));

    expect($zip->source)->toBe(['url' => 'https://example.org/notes.zip'])
        ->and($zip->where())->toBe('https://example.org/notes.zip')
        ->and($dir->where())->toBe('https://github.example/acme/notes at ' . CAT_COMMIT . ', path modules/notes')
        ->and($dir->toArray())->toBe(['version' => '1.0.0', 'form' => 'directory', 'source' => ['repository' => 'https://github.example/acme/notes', 'commit' => CAT_COMMIT, 'path' => 'modules/notes'], 'sha256' => CAT_SHA]);
});
