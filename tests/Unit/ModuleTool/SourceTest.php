<?php

declare(strict_types=1);

use ModuleTool\Entry;
use ModuleTool\Refusal;
use ModuleTool\Source;
use Tests\Support\FakeTransport;
use Tests\Support\ModuleFixtures;

/* Step 4 of `use` (docs/module-command.md, 7, 8 and 9). */

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
    $this->scratch = $this->base . '/scratch';
    mkdir($this->scratch);
    $this->git = ModuleFixtures::git($this->base);
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

function sourceEntry(string $commit, string $path = '.', array $changes = []): Entry
{
    return Entry::from('fixture-notes', '1.0.0', ModuleFixtures::entry($commit, str_repeat('a', 64), array_replace(['source' => ['repository' => ModuleFixtures::SOURCE, 'commit' => $commit, 'path' => $path]], $changes)));
}

it('exports the pinned commit, never the working copy (8)', function (): void {
    $commit = ModuleFixtures::source($this->base);
    // A later commit and an uncommitted change: neither is what was listed.
    ModuleFixtures::source($this->base, '.', fn (string $dir) => file_put_contents($dir . '/later.md', "later\n"));
    file_put_contents($this->base . '/repos/acme/notes/uncommitted.md', "dirty\n");

    $fetched = Source::fetch(sourceEntry($commit), $this->scratch, $this->git, new FakeTransport([]));

    expect($fetched['hash'])->toBeNull()
        ->and($fetched['dir'])->toBe($this->scratch . '/export')
        ->and(array_values(array_diff(scandir($fetched['dir']) ?: [], ['.', '..'])))->toBe(['MODULE-TEST.md', 'MODULE.md']);
});

it('exports a module that is one directory of a repository', function (): void {
    $commit = ModuleFixtures::source($this->base, 'modules/fixture-notes');

    $fetched = Source::fetch(sourceEntry($commit, 'modules/fixture-notes'), $this->scratch, $this->git, new FakeTransport([]));

    expect($fetched['dir'])->toBe($this->scratch . '/export/modules/fixture-notes')
        ->and(is_file($fetched['dir'] . '/MODULE.md'))->toBeTrue();
});

it('refuses a commit the repository does not have, as a fork-only commit would be (7)', function (): void {
    ModuleFixtures::source($this->base);

    expect(fn () => Source::fetch(sourceEntry(str_repeat('c', 40)), $this->scratch, $this->git, new FakeTransport([])))
        ->toThrow(Refusal::class, 'is not in ' . ModuleFixtures::SOURCE . ': a commit served only through a fork is refused (7)');
});

it('refuses a commit on none of the repository\'s branches or tags (7)', function (): void {
    ModuleFixtures::source($this->base);
    $orphan = ModuleFixtures::source($this->base, '.', fn (string $dir) => file_put_contents($dir . '/orphan.md', "x\n"));
    ModuleFixtures::run($this->base . '/repos/acme/notes', 'reset', '--quiet', '--hard', 'HEAD~1');

    expect(fn () => Source::fetch(sourceEntry($orphan), $this->scratch, $this->git, new FakeTransport([])))
        ->toThrow(Refusal::class, "is on none of " . ModuleFixtures::SOURCE . "'s own branches or tags (7)");
});

it('accepts a commit reachable only from a tag', function (): void {
    $repo = $this->base . '/repos/acme/notes';
    ModuleFixtures::source($this->base);
    $tagged = ModuleFixtures::source($this->base, '.', fn (string $dir) => file_put_contents($dir . '/tagged.md', "x\n"));
    ModuleFixtures::run($repo, 'tag', 'v1.0.0', $tagged);
    ModuleFixtures::run($repo, 'reset', '--quiet', '--hard', 'HEAD~1');

    expect(is_file(Source::fetch(sourceEntry($tagged), $this->scratch, $this->git, new FakeTransport([]))['dir'] . '/tagged.md'))->toBeTrue();
});

it('refuses a repository it cannot fetch, and a path not in the commit', function (): void {
    expect(fn () => Source::fetch(sourceEntry(str_repeat('c', 40)), $this->scratch, $this->git, new FakeTransport([])))->toThrow(Refusal::class, 'could not fetch');

    ModuleFixtures::remove($this->scratch);
    mkdir($this->scratch);
    $commit = ModuleFixtures::source($this->base);

    expect(fn () => Source::fetch(sourceEntry($commit, 'modules/missing'), $this->scratch, $this->git, new FakeTransport([])))->toThrow(Refusal::class, 'modules/missing is not in');
});

it('refuses an export it cannot unpack', function (): void {
    $commit = ModuleFixtures::source($this->base);
    // A non-empty directory where the export puts MODULE.md: tar cannot write it.
    mkdir($this->scratch . '/export/MODULE.md/in-the-way', 0o755, true);

    expect(fn () => Source::fetch(sourceEntry($commit), $this->scratch, $this->git, new FakeTransport([])))->toThrow(Refusal::class, 'could not unpack the export');
});

it('downloads a zip, hashes the file, and unpacks it (9, 10)', function (): void {
    $zipFile = $this->base . '/notes.zip';
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $zip->addFromString('fixture-notes/MODULE.md', "# x\n");
    $zip->close();
    $bytes = (string) file_get_contents($zipFile);
    $entry = sourceEntry(str_repeat('c', 40), '.', ['form' => 'zip', 'source' => ['url' => 'https://example.org/notes.zip']]);

    $fetched = Source::fetch($entry, $this->scratch, $this->git, new FakeTransport(['https://example.org/notes.zip' => [200, null, $bytes]]));

    expect($fetched['hash'])->toBe(hash('sha256', $bytes))
        ->and($fetched['dir'])->toBe($this->scratch . '/export/fixture-notes')
        ->and(file_get_contents($fetched['dir'] . '/MODULE.md'))->toBe("# x\n");
});

it('downloads a single prompt file and hashes it (9)', function (): void {
    $entry = sourceEntry(str_repeat('c', 40), '.', ['form' => 'file', 'source' => ['url' => 'https://example.org/fixture-notes.module.md']]);

    $fetched = Source::fetch($entry, $this->scratch, $this->git, new FakeTransport(['https://example.org/fixture-notes.module.md' => [200, null, "# all of it\n"]]));

    expect($fetched['hash'])->toBe(hash('sha256', "# all of it\n"))
        ->and(file_get_contents($fetched['dir'] . '/fixture-notes.module.md'))->toBe("# all of it\n");
});
