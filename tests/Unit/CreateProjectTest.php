<?php

declare(strict_types=1);

use Installer\CreateProject;

/*
 * Starting a project from Waffle (docs/create-project.md). The rename script is
 * executed against a scratch copy of the files it edits, never the repo itself;
 * the numbers cite that document's failure list.
 */

/**
 * A scratch tree holding the script and the files whose handling matters.
 */
function renameFixture(): string
{
    $root = dirname(__DIR__, 2);
    $dir = sys_get_temp_dir() . '/waffle-rename-' . bin2hex(random_bytes(6));

    foreach ([
        'tools/rename-project.sh',
        'tools/setup.sh',
        'tools/installer/CreateProject.php',
        'docs/create-project.md',
        'composer.json',
        'README.md',
        'LICENSE',
    ] as $path) {
        @mkdir(dirname($dir . '/' . $path), 0o777, true);
        copy($root . '/' . $path, $dir . '/' . $path);
    }

    // One of each placeholder, in the kinds of file they really live in.
    file_put_contents($dir . '/sample.php', <<<'PHP'
        <?php
        $message = 'Hello from Waffle.';
        $db = 'waffle_test';
        $cert = '/etc/letsencrypt/live/waffle.example.com/fullchain.pem';
        PHP);

    return $dir;
}

/**
 * @param list<string> $args
 *
 * @return array{0:int,1:string,2:string} [exit code, stdout, stderr]
 */
function runRename(string $dir, array $args): array
{
    $process = proc_open(
        array_merge(['bash', $dir . '/tools/rename-project.sh'], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
    );
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

/**
 * @return array<string,string> path => contents, for every file in the tree
 */
function treeContents(string $dir): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[substr($file->getPathname(), strlen($dir) + 1)] = (string) file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

beforeEach(function (): void {
    $this->dir = renameFixture();
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

it('leaves no placeholder outside the lines that point at Waffle itself (1)', function (): void {
    [$code, , $err] = runRename($this->dir, ['acme', 'Acme Ops', 'ops.acme.com']);

    expect($code)->toBe(0, $err);

    $leftovers = [];

    foreach (treeContents($this->dir) as $path => $contents) {
        if (in_array($path, ['tools/rename-project.sh', 'tools/setup.sh', 'tools/installer/CreateProject.php', 'docs/create-project.md', 'LICENSE'], true)) {
            continue;
        }

        foreach (explode("\n", $contents) as $number => $line) {
            if (!str_contains($line, 'treehousetim/waffle') && preg_match('/waffle/i', $line)) {
                $leftovers[] = $path . ':' . ($number + 1) . ': ' . trim($line);
            }
        }
    }

    expect($leftovers)->toBe([])
        ->and(file_get_contents($this->dir . '/sample.php'))
        ->toContain("'Hello from Acme Ops.'")
        ->toContain("'acme_test'")
        ->toContain('/live/ops.acme.com/');
});

it('gives the project its own package name and description (2)', function (): void {
    runRename($this->dir, ['acme', 'Acme Ops', 'ops.acme.com']);

    $composer = json_decode((string) file_get_contents($this->dir . '/composer.json'), true);

    expect($composer['name'])->toBe('acme/acme')
        ->and($composer['description'])->toBe('Acme Ops');
});

it('keeps the link back to Waffle, and keeps it intact on a second run (3, 10)', function (): void {
    runRename($this->dir, ['acme', 'Acme Ops', 'ops.acme.com']);
    $first = (string) file_get_contents($this->dir . '/README.md');

    // The script says to run it once; a second run must still do no harm.
    runRename($this->dir, ['acme', 'Acme Ops', 'ops.acme.com']);

    expect($first)->toContain('Built on [Waffle](https://github.com/treehousetim/waffle)')
        ->not->toContain('**W**affle')
        ->and(file_get_contents($this->dir . '/README.md'))->toBe($first);
});

it('refuses a slug that is not a usable database name, and touches nothing (4, 8)', function (string $slug): void {
    $before = treeContents($this->dir);

    [$code, , $err] = runRename($this->dir, [$slug, 'Acme', 'acme.example.org']);

    expect($code)->toBe(2)
        ->and($err)->toContain('slug')
        ->and(treeContents($this->dir))->toBe($before);
})->with(['Acme', 'acme-ops', '1acme', 'a', 'acme ops', str_repeat('a', 32)]);

it('refuses a display name that would break the files it is written into (5, 8)', function (string $name): void {
    $before = treeContents($this->dir);

    [$code, , $err] = runRename($this->dir, ['acme', $name, 'acme.example.org']);

    expect($code)->toBe(2)
        ->and($err)->toContain('name')
        ->and(treeContents($this->dir))->toBe($before);
})->with([
    'a single quote' => ["Tim's App"],
    'a double quote' => ['The "App"'],
    'a backslash' => ['Acme\\Ops'],
    'a dollar' => ['Acme $Ops'],
    'an ampersand, which sed reads as the match' => ['Acme & Co'],
    'a pipe, the sed delimiter' => ['Acme|Ops'],
    'an angle bracket' => ['<b>Acme</b>'],
    'empty' => [''],
]);

it('accepts a display name made of ordinary words', function (): void {
    [$code, , $err] = runRename($this->dir, ['acme', 'Acme Ops 2.0 - North_East', 'acme.example.org']);

    expect($code)->toBe(0, $err)
        ->and(file_get_contents($this->dir . '/sample.php'))->toContain("'Hello from Acme Ops 2.0 - North_East.'");
});

it('refuses a hostname that is not a plain DNS name, and touches nothing (6, 8)', function (string $host): void {
    $before = treeContents($this->dir);

    [$code, , $err] = runRename($this->dir, ['acme', 'Acme', $host]);

    expect($code)->toBe(2)
        ->and($err)->toContain('hostname')
        ->and(treeContents($this->dir))->toBe($before);
})->with(['localhost', 'Acme.example.org', 'acme.example.org;', 'acme example.org', '-acme.example.org', 'https://acme.example.org', '']);

it('refuses to run over an existing database directory (7, 8)', function (): void {
    mkdir($this->dir . '/docker/data/mysql', 0o777, true);
    touch($this->dir . '/docker/data/mysql/ibdata1');
    $before = treeContents($this->dir);

    [$code, , $err] = runRename($this->dir, ['acme', 'Acme', 'acme.example.org']);

    expect($code)->toBe(1)
        ->and($err)->toContain('docker/data')
        ->and(treeContents($this->dir))->toBe($before);
});

it('never touches the installer, its autoload entry, this document or the licence (9)', function (): void {
    $before = treeContents($this->dir);

    runRename($this->dir, ['acme', 'Acme Ops', 'ops.acme.com']);

    $after = treeContents($this->dir);
    $composer = json_decode($after['composer.json'], true);

    foreach (['tools/rename-project.sh', 'tools/setup.sh', 'tools/installer/CreateProject.php', 'docs/create-project.md', 'LICENSE'] as $path) {
        expect($after[$path])->toBe($before[$path], $path);
    }

    expect($composer['autoload-dev']['psr-4']['Installer\\'] ?? null)->toBe('tools/installer/')
        ->and($composer['scripts']['post-create-project-cmd'] ?? null)->toBe(['Installer\\CreateProject::postCreate']);
});

/**
 * @param list<string> $args
 * @param array<string,string> $env extra environment, e.g. a PATH with shims
 *
 * @return array{0:int,1:string,2:string} [exit code, stdout, stderr]
 */
function runSetup(string $dir, array $args, array $env = []): array
{
    $process = proc_open(
        array_merge(['bash', $dir . '/tools/setup.sh'], $args),
        // No terminal on stdin: the script must never wait for an answer here.
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
        array_merge(['PATH' => (string) getenv('PATH'), 'NO_COLOR' => '1'], $env),
    );
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('suggests a valid slug for whatever the directory is called (12)', function (string $directory, string $slug): void {
    [$code, $out] = runSetup($this->dir, ['--suggest', $directory]);

    expect($code)->toBe(0)
        ->and(explode("\n", $out)[0])->toBe($slug)
        ->and($slug)->toMatch('/^[a-z][a-z0-9_]{1,30}$/');
})->with([
    'already fine' => ['acme', 'acme'],
    'capitals' => ['ACME', 'acme'],
    'spaces and dashes' => ['My App-2', 'my_app_2'],
    'a leading digit' => ['2024-site', 'app_2024_site'],
    'one character' => ['x', 'x_app'],
    'nothing usable' => ['---', 'app'],
    'too long' => [str_repeat('abc', 20), substr(str_repeat('abc', 20), 0, 31)],
]);

it('suggests a display name and hostname from the slug', function (): void {
    [, $out] = runSetup($this->dir, ['--suggest', 'acme_ops']);

    expect($out)->toBe("acme_ops\nAcme Ops\nacme-ops.example.com\n");
});

it('sets the project up from flags alone, without a terminal (13)', function (): void {
    [$code, $out, $err] = runSetup($this->dir, ['--slug', 'acme', '--name', 'Acme Ops', '--host', 'ops.acme.com', '--skip-locks', '--no-git']);

    expect($code)->toBe(0, $err)
        ->and($out)->toContain('renamed: slug acme, name "Acme Ops", host ops.acme.com')
        ->and(json_decode((string) file_get_contents($this->dir . '/composer.json'), true)['name'])->toBe('acme/acme');
});

it('takes the suggestions with --yes', function (): void {
    [$code, , $err] = runSetup($this->dir, ['--yes', '--skip-locks', '--no-git']);
    $slug = explode("\n", runSetup($this->dir, ['--suggest', basename($this->dir)])[1])[0];

    expect($code)->toBe(0, $err)
        ->and(json_decode((string) file_get_contents($this->dir . '/composer.json'), true)['name'])->toBe($slug . '/' . $slug);
});

it('refuses to guess without a terminal, and touches nothing (14)', function (): void {
    $before = treeContents($this->dir);

    [$code, , $err] = runSetup($this->dir, ['--slug', 'acme', '--skip-locks', '--no-git']);

    expect($code)->toBe(2)
        ->and($err)->toContain('--slug, --name and --host')
        ->and(treeContents($this->dir))->toBe($before);
});

it('passes on the rename script\'s refusal, and touches nothing (15)', function (): void {
    $before = treeContents($this->dir);

    [$code, , $err] = runSetup($this->dir, ['--slug', 'Acme', '--name', 'Acme', '--host', 'acme.example.org', '--skip-locks', '--no-git']);

    expect($code)->toBe(2)
        ->and($err)->toContain('slug')
        ->and(treeContents($this->dir))->toBe($before);
});

it('skips the rename when the project is already named, so it can be run again (16)', function (): void {
    runSetup($this->dir, ['--slug', 'acme', '--name', 'Acme', '--host', 'acme.example.org', '--skip-locks', '--no-git']);
    $before = treeContents($this->dir);

    [$code, $out, $err] = runSetup($this->dir, ['--skip-locks', '--no-git']);

    expect($code)->toBe(0, $err)
        ->and($out)->toContain('already named (acme/acme); skipping')
        ->and(treeContents($this->dir))->toBe($before);
});

it('stops on a native Windows shell and points at WSL2 (17)', function (string $uname): void {
    // A uname shim first on PATH stands in for Git Bash, MSYS2 and Cygwin.
    $bin = $this->dir . '/.shim';
    mkdir($bin);
    file_put_contents($bin . '/uname', "#!/bin/sh\necho {$uname}\n");
    chmod($bin . '/uname', 0o755);
    $before = treeContents($this->dir);

    [$code, $out] = runSetup($this->dir, ['--yes', '--skip-locks', '--no-git'], ['PATH' => $bin . ':' . getenv('PATH')]);

    expect($code)->toBe(1)
        ->and($out)->toContain('WSL2')
        ->and(treeContents($this->dir))->toBe($before);
})->with(['MINGW64_NT-10.0-22631', 'MSYS_NT-10.0', 'CYGWIN_NT-10.0']);

it('rejects an option it does not know', function (): void {
    [$code, , $err] = runSetup($this->dir, ['--slgu', 'acme']);

    expect($code)->toBe(2)
        ->and($err)->toContain('unknown option: --slgu');
});

it('asks nothing and renames nothing when there is no terminal (11)', function (): void {
    expect(CreateProject::shouldAsk(false))->toBeFalse()
        ->and(CreateProject::shouldAsk(true))->toBeTrue();
});
