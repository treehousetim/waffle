<?php

declare(strict_types=1);

use ModuleTool\Manifest;
use ModuleTool\Refusal;
use Tests\Support\FakeTransport;
use Tests\Support\ModuleFixtures;

/*
 * The module command's PHP half, end to end against real git repositories
 * (docs/module-command.md). The numbers cite its failure list.
 */

const RUN = '.waffle/scratch/run-1';

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
    $this->app = ModuleFixtures::app($this->base);
    $this->out = fopen('php://memory', 'w+');
    $this->list = function (array $versions, ?callable $edit = null, string $listedAs = '1.0.0', array $changes = []): string {
        $commit = ModuleFixtures::source($this->base, '.', $edit);
        ModuleFixtures::catalogue($this->base, $versions + [$listedAs => ModuleFixtures::entry($commit, ModuleFixtures::hash($this->base, $commit), $changes)]);

        return $commit;
    };
    $this->command = fn (?FakeTransport $transport = null) => ModuleFixtures::command($this->base, $this->app, $this->out, $transport);
    $this->said = function (): string {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    };
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

it('checks, shows, stages, prompts and records a module', function (): void {
    ($this->list)([]);
    $command = ($this->command)();

    expect($command->prepare('fixture-notes', RUN, false))->toBe(0);

    $shown = ($this->said)();
    expect($shown)->toContain('Fixture notes (fixture-notes 1.0.0)')
        ->and($shown)->toContain('Reviewed for Waffle Iron by a-reviewer.')
        ->and($shown)->toContain('From directory: ' . ModuleFixtures::SOURCE . ' at ')
        ->and($shown)->toContain('Unproven: nobody has yet built it into a fresh Waffle app')
        ->and($shown)->toContain('Permissions it adds: fixture-notes-view')
        ->and($shown)->toContain('  - Anyone not signed in gets `401`.')
        ->and($shown)->toContain('Tables it creates or writes to: fixture_notes')
        ->and($shown)->toContain("Libraries: none beyond Waffle's stack")
        ->and($shown)->toContain('Config: none')
        ->and($shown)->toContain('HTML comments: none.')
        // Nothing outside scratch yet, and .waffle/ ignores itself.
        ->and(is_dir($this->app . '/.waffle/modules'))->toBeFalse()
        ->and(file_get_contents($this->app . '/.waffle/.gitignore'))->toBe("*\n");

    expect($command->stage(RUN))->toBe(0)
        ->and(is_file($this->app . '/.waffle/modules/fixture-notes@1.0.0/MODULE.md'))->toBeTrue()
        ->and($command->prompt('fixture-notes'))->toBe(0);

    $prompt = ($this->said)();
    expect($prompt)->toContain('Install the Waffle module fixture-notes 1.0.0. Its files are in .waffle/modules/fixture-notes@1.0.0/')
        ->and($prompt)->toContain('AGENTS.md wins every disagreement')
        ->and($prompt)->toContain('wait for my go-ahead before building')
        ->and($prompt)->toContain('./tools/module.sh record fixture-notes');

    expect($command->recordCheck('fixture-notes'))->toBe(0)
        ->and($command->recordWrite('fixture-notes'))->toBe(0)
        ->and((new Manifest($this->app . '/modules.json'))->get('fixture-notes'))->toMatchArray([
            'version' => '1.0.0',
            'form' => 'directory',
            'date' => '2026-10-14',
        ])
        ->and(($this->said)())->toContain('add a line under [Unreleased] in CHANGELOG.md');
});

it('refuses a module that is already installed, and an upgrade of one that is not (25)', function (): void {
    ($this->list)([]);
    (new Manifest($this->app . '/modules.json'))->put('fixture-notes', ['version' => '1.0.0']);

    expect(fn () => ($this->command)()->prepare('fixture-notes', RUN, false))->toThrow(Refusal::class, 'already installed, at 1.0.0: use `upgrade fixture-notes` for a newer version (25)');

    unlink($this->app . '/modules.json');

    expect(fn () => ($this->command)()->prepare('fixture-notes', RUN, true))->toThrow(Refusal::class, 'is not installed here');
});

it('refuses a name or version that is not one, before using it (20)', function (string $spec): void {
    expect(fn () => ($this->command)()->prepare($spec, RUN, false))->toThrow(Refusal::class, 'is not a module name, or name@X.Y.Z');
})->with([
    'a shell command' => ['notes;rm -rf ~'],
    'an option' => ['--upload-pack=x'],
    'a path' => ['../notes'],
    'a short version' => ['notes@1.0'],
    'an escape' => ["notes\e[2J"],
]);

it('refuses a scratch directory that is not a new one under .waffle/scratch', function (string $scratch): void {
    ($this->list)([]);
    is_dir($this->app . '/.waffle/scratch/run-old') || mkdir($this->app . '/.waffle/scratch/run-old', 0o777, true);

    expect(fn () => ($this->command)()->prepare('fixture-notes', $scratch, false))->toThrow(Refusal::class, 'is not a new directory under .waffle/scratch/');
})->with(['elsewhere' => ['tmp/run'], 'climbing out' => ['.waffle/scratch/../../x'], 'existing' => ['.waffle/scratch/run-old']]);

it('refuses a module whose hash does not match, staging nothing (15)', function (): void {
    $commit = ModuleFixtures::source($this->base);
    ModuleFixtures::catalogue($this->base, ['1.0.0' => ModuleFixtures::entry($commit, str_repeat('0', 64))]);

    expect(fn () => ($this->command)()->prepare('fixture-notes', RUN, false))->toThrow(Refusal::class, 'does not match the catalogue')
        ->and(is_dir($this->app . '/.waffle/modules'))->toBeFalse();
});

it('refuses a module with hidden characters, whatever its hash (13)', function (): void {
    ($this->list)([], fn (string $dir) => file_put_contents($dir . '/MODULE.md', str_replace('A test fixture', "A test\u{200B} fixture", (string) file_get_contents($dir . '/MODULE.md'))));

    expect(fn () => ($this->command)()->prepare('fixture-notes', RUN, false))->toThrow(Refusal::class, 'MODULE.md:19: hidden or control character U+200B (13)');
});

it('refuses a module that fails the format check (17)', function (): void {
    ($this->list)([], fn (string $dir) => file_put_contents($dir . '/MODULE.md', str_replace('## Purpose', '## Why', (string) file_get_contents($dir . '/MODULE.md'))));

    expect(fn () => ($this->command)()->prepare('fixture-notes', RUN, false))->toThrow(Refusal::class, "fails the format check (17):\n  MODULE.md: no '## Purpose' section");
});

it('refuses a module whose front matter is not what the catalogue lists (16)', function (): void {
    ($this->list)([], null, '1.1.0');

    expect(fn () => ($this->command)()->prepare('fixture-notes', RUN, false))->toThrow(Refusal::class, 'MODULE.md says it is fixture-notes 1.0.0, and the catalogue lists it as fixture-notes 1.1.0 (16)');
});

it('shows libraries, config, migrations, binaries, comments and proofs (14, 18)', function (): void {
    ($this->list)([], function (string $dir): void {
        $module = (string) file_get_contents($dir . '/MODULE.md');
        $module = str_replace(['libraries: none', 'config: none'], ['libraries: guzzlehttp/guzzle ^8.1, some/unapproved', 'config: NOTES_KEY'], $module);
        $module = str_replace('## Failure list', "Also a migration: `\$this->table('fixture_tags')`.\n\n<!-- read me -->\n\n## Failure list", $module);
        file_put_contents($dir . '/MODULE.md', $module);
        mkdir($dir . '/reference');
        file_put_contents($dir . '/reference/logo.png', "\x89PNG\0");
    }, '1.0.0', ['proven-on' => ['0.1.0']]);

    ($this->command)()->prepare('fixture-notes', RUN, false);
    $shown = ($this->said)();

    expect($shown)->toContain('Proven on Waffle 0.1.0.')
        ->and($shown)->toContain('Tables it creates or writes to: fixture_notes, fixture_tags')
        ->and($shown)->toContain("  guzzlehttp/guzzle ^8.1 (on AGENTS.md §2's list)")
        ->and($shown)->toContain("  some/unapproved -- NOT on AGENTS.md §2's approved list: it needs your approval before it is added (18)")
        ->and($shown)->toContain('Config: NOTES_KEY (secrets go in Doppler, never in a file)')
        ->and($shown)->toContain('Binary files, not checked as text: reference/logo.png')
        ->and($shown)->toContain("HTML comments, which a rendered page hides and an agent reads. Read each one (14):\n  MODULE.md:")
        ->and($shown)->toContain('    <!-- read me -->');
});

it('treats every library as unapproved when AGENTS.md is missing (18)', function (): void {
    ($this->list)([], fn (string $dir) => file_put_contents($dir . '/MODULE.md', str_replace('libraries: none', 'libraries: guzzlehttp/guzzle', (string) file_get_contents($dir . '/MODULE.md'))));
    unlink($this->app . '/AGENTS.md');

    ($this->command)()->prepare('fixture-notes', RUN, false);

    expect(($this->said)())->toContain("  guzzlehttp/guzzle -- NOT on AGENTS.md §2's approved list");
});

it('installs a zip module, and a single prompt file', function (string $form): void {
    $dir = dirname(__DIR__, 2) . '/Support/Fixtures/modules/fixture-notes';
    $module = (string) file_get_contents($dir . '/MODULE.md');
    $test = (string) file_get_contents($dir . '/MODULE-TEST.md');

    if ($form === 'zip') {
        $file = $this->base . '/notes.zip';
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        $zip->addFromString('fixture-notes/MODULE.md', $module);
        $zip->addFromString('fixture-notes/MODULE-TEST.md', $test);
        $zip->close();
        $bytes = (string) file_get_contents($file);
    } else {
        $bytes = $module . "\n" . $test;
    }

    $url = 'https://example.org/fixture-notes.' . $form;
    ModuleFixtures::catalogue($this->base, ['1.0.0' => ModuleFixtures::entry(str_repeat('c', 40), hash('sha256', $bytes), ['form' => $form, 'source' => ['url' => $url]])]);
    $command = ($this->command)(new FakeTransport([$url => [200, null, $bytes]]));

    expect($command->prepare('fixture-notes', RUN, false))->toBe(0)
        ->and($command->stage(RUN))->toBe(0)
        ->and($command->recordCheck('fixture-notes'))->toBe(0);
})->with(['zip', 'file']);

it('upgrades to the newest version above the one installed', function (): void {
    $old = ModuleFixtures::source($this->base);
    $new = ModuleFixtures::source($this->base, '.', fn (string $dir) => file_put_contents($dir . '/MODULE.md', str_replace('version: 1.0.0', 'version: 1.1.0', (string) file_get_contents($dir . '/MODULE.md'))));
    ModuleFixtures::catalogue($this->base, [
        '1.0.0' => ModuleFixtures::entry($old, ModuleFixtures::hash($this->base, $old)),
        '1.1.0' => ModuleFixtures::entry($new, ModuleFixtures::hash($this->base, $new)),
    ]);
    (new Manifest($this->app . '/modules.json'))->put('fixture-notes', ['version' => '1.0.0']);
    $command = ($this->command)();

    expect($command->prepare('fixture-notes', RUN, true))->toBe(0)
        ->and(($this->said)())->toContain('(fixture-notes 1.1.0, replacing 1.0.0)');

    $command->stage(RUN);
    $command->prompt('fixture-notes');

    expect(($this->said)())->toContain('Upgrade the Waffle module fixture-notes from 1.0.0 to 1.1.0; read the Upgrading entries in its MODULE.md newer than 1.0.0');
});

it('stages over an earlier, abandoned staging of the same version', function (): void {
    ($this->list)([]);
    mkdir($this->app . '/.waffle/modules/fixture-notes@1.0.0/old', 0o777, true);
    file_put_contents($this->app . '/.waffle/modules/fixture-notes@1.0.0/old/stale.md', "stale\n");
    $command = ($this->command)();
    $command->prepare('fixture-notes', RUN, false);

    expect($command->stage(RUN))->toBe(0)
        ->and(file_exists($this->app . '/.waffle/modules/fixture-notes@1.0.0/old'))->toBeFalse();
});

it('refuses to stage what was not checked, or to prompt or record what was not staged', function (): void {
    expect(fn () => ($this->command)()->stage('.waffle/scratch/run-none'))->toThrow(Refusal::class, 'holds no checked module')
        ->and(fn () => ($this->command)()->stage('../elsewhere'))->toThrow(Refusal::class, 'holds no checked module')
        ->and(fn () => ($this->command)()->prompt('fixture-notes'))->toThrow(Refusal::class, 'nothing is staged for fixture-notes')
        ->and(fn () => ($this->command)()->recordCheck('fixture-notes'))->toThrow(Refusal::class, 'nothing is staged');
});

it('refuses to record a staged copy that changed or went (24)', function (): void {
    ($this->list)([]);
    $command = ($this->command)();
    $command->prepare('fixture-notes', RUN, false);
    $command->stage(RUN);
    $staged = $this->app . '/.waffle/modules/fixture-notes@1.0.0';
    file_put_contents($staged . '/MODULE.md', "# changed\n", FILE_APPEND);

    expect(fn () => $command->recordCheck('fixture-notes'))->toThrow(Refusal::class, 'changed after it was checked; run `use fixture-notes` again (24)');

    ModuleFixtures::remove($staged);

    expect(fn () => $command->recordCheck('fixture-notes'))->toThrow(Refusal::class, 'is gone; run `use fixture-notes` again (24)');
});

it('records only when the shipped tests are in the app unchanged (23)', function (): void {
    ($this->list)([], function (string $dir): void {
        mkdir($dir . '/tests/Unit', 0o777, true);
        file_put_contents($dir . '/tests/Unit/NotesTest.php', "<?php\n// shipped\n");
    });
    $command = ($this->command)();
    $command->prepare('fixture-notes', RUN, false);
    $command->stage(RUN);

    expect(fn () => $command->recordCheck('fixture-notes'))->toThrow(Refusal::class, 'ships tests/Unit/NotesTest.php, and the app does not have it: shipped tests are copied in unchanged (23)');

    mkdir($this->app . '/tests/Unit', 0o777, true);
    file_put_contents($this->app . '/tests/Unit/NotesTest.php', "<?php\n// weakened\n");

    expect(fn () => $command->recordCheck('fixture-notes'))->toThrow(Refusal::class, 'tests/Unit/NotesTest.php differs from the one the module ships: shipped tests are copied in unchanged (23)');

    file_put_contents($this->app . '/tests/Unit/NotesTest.php', "<?php\n// shipped\n");

    expect($command->recordCheck('fixture-notes'))->toBe(0);
});

it('lists installed modules with a newer version this app can take', function (): void {
    $commit = ($this->list)(['1.1.0' => ModuleFixtures::entry(str_repeat('c', 40), str_repeat('d', 64))]);
    $command = ($this->command)();

    expect($command->outdated())->toBe(0)
        ->and(($this->said)())->toContain('Every installed module is at the newest version');

    (new Manifest($this->app . '/modules.json'))->put('fixture-notes', ['version' => '1.0.0']);
    (new Manifest($this->app . '/modules.json'))->put('unlisted', ['version' => '1.0.0']);

    expect($command->outdated())->toBe(0)
        ->and(($this->said)())->toContain('fixture-notes 1.0.0 -> 1.1.0: ./tools/module.sh upgrade fixture-notes');
});

it('verifies installed modules against the catalogue, changing nothing (26, 27)', function (): void {
    $commit = ($this->list)([]);
    $entry = ModuleFixtures::entry($commit, ModuleFixtures::hash($this->base, $commit));
    $manifest = new Manifest($this->app . '/modules.json');
    $command = ($this->command)();

    expect($command->verify())->toBe(0)
        ->and(($this->said)())->toContain('No module is installed.');

    $manifest->put('fixture-notes', ['version' => '1.0.0', 'form' => 'directory', 'source' => $entry['source'], 'sha256' => $entry['sha256']]);

    expect($command->verify())->toBe(0)
        ->and(($this->said)())->toContain('Every installed module matches the catalogue.');

    $before = file_get_contents($this->app . '/modules.json');
    ModuleFixtures::catalogue($this->base, ['1.0.0' => array_replace($entry, ['sha256' => str_repeat('e', 64), 'yanked' => 'found harmful'])]);

    expect($command->verify())->toBe(1)
        ->and(($this->said)())->toContain('WARNING: fixture-notes 1.0.0 in the catalogue is not what this app recorded')
        ->and(($this->said)())->toContain('WARNING: fixture-notes 1.0.0 was yanked: found harmful (27).')
        ->and(file_get_contents($this->app . '/modules.json'))->toBe($before);

    $manifest->put('gone', ['version' => '1.0.0']);

    expect($command->verify())->toBe(1)
        ->and(($this->said)())->toContain('WARNING: gone 1.0.0 is no longer in the catalogue');
});

it('keeps .waffle/ out of git from the first thing that writes there', function (string $verb): void {
    ($this->list)([]);
    $command = ($this->command)();

    match ($verb) {
        'search' => $command->search([]),
        'info' => $command->info('fixture-notes'),
        'outdated' => $command->outdated(),
        'verify' => $command->verify(),
    };

    expect(is_dir($this->app . '/.waffle/iron'))->toBeTrue()
        ->and(file_get_contents($this->app . '/.waffle/.gitignore'))->toBe("*\n");
})->with(['search', 'info', 'outdated', 'verify']);

it('searches by every word given, and says when nothing matches', function (): void {
    ($this->list)(['1.1.0' => ModuleFixtures::entry(str_repeat('c', 40), str_repeat('d', 64), ['reviewed-by' => null])]);
    $command = ($this->command)();

    expect($command->search(['SMALLEST', 'module']))->toBe(0)
        ->and(($this->said)())->toContain('fixture-notes 1.1.0, not reviewed yet: The smallest module the format allows.');

    expect($command->search(['quotes']))->toBe(0)
        ->and(($this->said)())->toContain('No module in the catalogue matches.');

    ModuleFixtures::catalogue($this->base, ['1.1.0' => ModuleFixtures::entry(str_repeat('c', 40), str_repeat('d', 64), ['yanked' => 'no'])]);
    $command->search([]);

    expect(($this->said)())->toContain('fixture-notes 1.1.0, yanked:');
});

it('describes every version and whether this app can take it', function (): void {
    ($this->list)(['2.0.0' => ModuleFixtures::entry(str_repeat('c', 40), str_repeat('d', 64), ['waffle' => '>=1.0', 'reviewed-by' => null, 'proven-on' => ['1.0.0']])]);

    expect(($this->command)()->info('fixture-notes'))->toBe(0);

    $said = ($this->said)();
    expect($said)->toContain('fixture-notes: Fixture notes')
        ->and($said)->toContain('By treehousetim, MIT licence.')
        ->and($said)->toContain('2.0.0 (cannot be installed here: not reviewed yet)')
        ->and($said)->toContain('  Proven on: 1.0.0')
        ->and($said)->toContain('1.0.0 (can be installed here)')
        ->and($said)->toContain('  Proven on: nowhere yet (unproven)')
        ->and($said)->toContain('  Reviewed:  by a-reviewer')
        ->and($said)->toContain('  Reviewed:  not yet');
});

it('checks git, the extensions and the Waffle version (28, 32)', function (): void {
    expect(($this->command)()->doctor())->toBe(0)
        ->and(($this->said)())->toContain('ok    Waffle 0.1.0');

    file_put_contents($this->app . '/CHANGELOG.md', "# Changelog\n");

    expect(($this->command)()->doctor())->toBe(1)
        ->and(($this->said)())->toContain('FAIL  cannot tell which Waffle version');
});
