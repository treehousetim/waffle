<?php

declare(strict_types=1);

use ModuleTool\Contents;
use ModuleTool\DirectoryHash;
use Tests\Support\ModuleFixtures;

/*
 * Step 6 of `use`, and the hash (docs/module-command.md): what may be in a
 * module, and the same SHA-256 the documented shell pipeline gives.
 */

beforeEach(function (): void {
    $this->dir = ModuleFixtures::base() . '/module';
    mkdir($this->dir . '/reference', 0o777, true);
    file_put_contents($this->dir . '/MODULE.md', "# Notes\n\nPlain text → fine, café too.\r\n");
    file_put_contents($this->dir . '/reference/notes.php', "<?php\n");
});

afterEach(function (): void {
    ModuleFixtures::remove(dirname($this->dir));
});

it('passes plain files and lists them sorted', function (): void {
    $scan = Contents::scan($this->dir);

    expect($scan->refusals)->toBe([])
        ->and($scan->files)->toBe(['MODULE.md', 'reference/notes.php'])
        ->and($scan->binaries)->toBe([])
        ->and($scan->comments)->toBe([]);
});

it('refuses a symlink, to a file or a directory (11)', function (): void {
    symlink('MODULE.md', $this->dir . '/link.md');
    symlink('reference', $this->dir . '/linked-dir');

    expect(Contents::scan($this->dir)->refusals)->toBe(['link.md: a symlink (11)', 'linked-dir: a symlink (11)']);
});

it('refuses a file that is not a regular file (11)', function (): void {
    posix_mkfifo($this->dir . '/pipe', 0o600);

    expect(Contents::scan($this->dir)->refusals)->toBe(['pipe: not a regular file (11)']);
});

it('refuses a file name that is not printable ASCII (12)', function (string $name, string $shown): void {
    file_put_contents($this->dir . '/' . $name, "x\n");

    expect(Contents::scan($this->dir)->refusals)->toBe(["{$shown}: a file name with something other than printable ASCII (12)"]);
})->with([
    'accented' => ['café.md', 'café.md'],
    'a control character' => ["a\tb.md", "a\tb.md"],
    'an escape' => ["a\eb.md", 'a\x1Bb.md'],
]);

it('refuses hidden and control characters in text, naming file, line and code point (13)', function (string $character, string $codePoint): void {
    file_put_contents($this->dir . '/reference/notes.php', "<?php\n// fine\n// hidden{$character}here\n");

    expect(Contents::scan($this->dir)->refusals)->toBe(["reference/notes.php:3: hidden or control character U+{$codePoint} (13)"]);
})->with([
    'an escape' => ["\e", '001B'],
    'a bell' => ["\x07", '0007'],
    'a form feed' => ["\f", '000C'],
    'delete' => ["\x7F", '007F'],
    'a C1 control' => ["\u{85}", '0085'],
    'a zero-width space' => ["\u{200B}", '200B'],
    'a zero-width joiner' => ["\u{200D}", '200D'],
    'a right-to-left override' => ["\u{202E}", '202E'],
    'a bidi isolate' => ["\u{2066}", '2066'],
    'a byte-order mark' => ["\u{FEFF}", 'FEFF'],
    'a soft hyphen' => ["\u{AD}", '00AD'],
    'a tag character' => ["\u{E0041}", 'E0041'],
    'a line separator' => ["\u{2028}", '2028'],
    'a paragraph separator' => ["\u{2029}", '2029'],
    'a variation selector' => ["\u{FE0F}", 'FE0F'],
    'a supplementary variation selector' => ["\u{E0100}", 'E0100'],
]);

it('sets aside a binary file, unread, and lists it (6)', function (): void {
    file_put_contents($this->dir . '/reference/logo.png', "\x89PNG\0\0\e\u{202E}");

    $scan = Contents::scan($this->dir);

    expect($scan->refusals)->toBe([])
        ->and($scan->binaries)->toBe(['reference/logo.png'])
        ->and($scan->files)->toContain('reference/logo.png');
});

it('refuses a file that is neither UTF-8 text nor binary (33)', function (): void {
    file_put_contents($this->dir . '/latin1.md', "caf\xE9\n");

    expect(Contents::scan($this->dir)->refusals)->toBe(['latin1.md: neither UTF-8 text nor binary (33)']);
});

it('lists every HTML comment with its file and line, unclosed ones too (14)', function (): void {
    file_put_contents($this->dir . '/MODULE.md', "# Notes\n<!-- one -->\ntext\n<!-- two\nlines -->\n");
    file_put_contents($this->dir . '/reference/notes.php', "<?php\n<!-- never closed");

    expect(Contents::scan($this->dir)->comments)->toBe([
        ['file' => 'MODULE.md', 'line' => 2, 'text' => '<!-- one -->'],
        ['file' => 'MODULE.md', 'line' => 4, 'text' => "<!-- two\nlines -->"],
        ['file' => 'reference/notes.php', 'line' => 2, 'text' => '<!-- never closed'],
    ]);
});

it('skips a top-level .git only when asked to (8)', function (): void {
    mkdir($this->dir . '/.git');
    file_put_contents($this->dir . '/.git/HEAD', "ref: refs/heads/main\n");
    mkdir($this->dir . '/reference/.git');
    file_put_contents($this->dir . '/reference/.git/x', "x\n");

    expect(Contents::scan($this->dir, true)->files)->toBe(['MODULE.md', 'reference/.git/x', 'reference/notes.php'])
        ->and(Contents::scan($this->dir)->files)->toContain('.git/HEAD');
});

it('hashes a directory exactly as the documented shell pipeline does (8)', function (): void {
    file_put_contents($this->dir . '/.hidden', "counts\n");
    file_put_contents($this->dir . '/B.md', "upper case sorts first\n");
    $shell = trim((string) shell_exec('cd ' . escapeshellarg($this->dir) . ' && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -d" " -f1'));

    expect(DirectoryHash::of($this->dir, Contents::scan($this->dir)->files))->toBe($shell)
        ->and(DirectoryHash::ofFile($this->dir . '/B.md'))->toBe(hash('sha256', "upper case sorts first\n"));
});
