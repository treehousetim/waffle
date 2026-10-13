<?php

declare(strict_types=1);

use ModuleTool\Refusal;
use ModuleTool\Zip;
use Tests\Support\ModuleFixtures;

/* Step 5 of `use` (docs/module-command.md, 10 and 12). */

/** @param array<string,string> $entries name => contents; a name ending in / is a directory */
function makeZip(string $file, array $entries, array $symlinks = []): void
{
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $contents);
    }

    foreach ($symlinks as $name => $target) {
        $zip->addFromString($name, $target);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0o120777 << 16));
    }

    $zip->close();
}

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
    $this->zip = $this->base . '/notes.zip';
    $this->dest = $this->base . '/export';
    mkdir($this->dest);
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

it('unpacks a module held in <name>/', function (): void {
    makeZip($this->zip, ['notes/' => '', 'notes/MODULE.md' => "# Notes\n", 'notes/reference/a.php' => "<?php\n"]);

    Zip::extract($this->zip, $this->dest, 'notes');

    expect(file_get_contents($this->dest . '/notes/MODULE.md'))->toBe("# Notes\n")
        ->and(file_get_contents($this->dest . '/notes/reference/a.php'))->toBe("<?php\n");
});

it('refuses a dangerous entry before writing anything (10, 12)', function (array $entries, string $message, array $symlinks = []): void {
    makeZip($this->zip, $entries, $symlinks);

    expect(fn () => Zip::extract($this->zip, $this->dest, 'notes'))->toThrow(Refusal::class, $message)
        ->and(glob($this->dest . '/*'))->toBe([]);
})->with([
    'an absolute path' => [['notes/MODULE.md' => 'x', '/etc/cron.d/x' => 'x'], "an absolute path, '/etc/cron.d/x'"],
    'a drive letter' => [['notes/MODULE.md' => 'x', 'C:/x' => 'x'], "an absolute path, 'C:/x'"],
    'a backslash' => [['notes/MODULE.md' => 'x', 'notes\\..\\x' => 'x'], 'a backslash'],
    'a .. segment' => [['notes/MODULE.md' => 'x', 'notes/../../x' => 'x'], "a '..' segment"],
    'outside <name>/' => [['notes/MODULE.md' => 'x', 'other/MODULE.md' => 'x'], "outside 'notes/', 'other/MODULE.md'"],
    'a name that is not ASCII' => [['notes/MODULE.md' => 'x', 'notes/café.md' => 'x'], 'not printable ASCII'],
    'a symlink' => [['notes/MODULE.md' => 'x'], "a symlink, 'notes/link'", ['notes/link' => '/etc/passwd']],
]);

it('refuses a zip that unpacks past the limit, counting what it writes (10)', function (): void {
    makeZip($this->zip, ['notes/MODULE.md' => str_repeat('a', 2048)]);

    expect(fn () => Zip::extract($this->zip, $this->dest, 'notes', 1024))->toThrow(Refusal::class, 'more than 0 MB');
});

it('refuses a download that is not a zip (10)', function (): void {
    file_put_contents($this->zip, 'not a zip');

    expect(fn () => Zip::extract($this->zip, $this->dest, 'notes'))->toThrow(Refusal::class, 'not a zip file');
});

it('allows ten megabytes by default', function (): void {
    expect(Zip::LIMIT)->toBe(10 * 1024 * 1024);
});
