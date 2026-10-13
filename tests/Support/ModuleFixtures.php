<?php

declare(strict_types=1);

namespace Tests\Support;

use ModuleTool\Command;
use ModuleTool\Git;
use ModuleTool\Iron;
use ModuleTool\Transport;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Real git repositories for the module command's tests, with no network:
 * a git config maps https://github.example/ onto local directories, so the
 * catalogue and module sources are fetched by real git from "https" URLs.
 */
final class ModuleFixtures
{
    public const HOST = 'https://github.example/';
    public const IRON = self::HOST . 'treehousetim/waffle-iron';
    public const SOURCE = self::HOST . 'acme/notes';

    public static function base(): string
    {
        $base = sys_get_temp_dir() . '/waffle-module-' . bin2hex(random_bytes(6));
        mkdir($base . '/repos', 0o777, true);
        file_put_contents($base . '/gitconfig', "[url \"{$base}/repos/\"]\n\tinsteadOf = " . self::HOST . "\n[protocol \"file\"]\n\tallow = always\n");

        return $base;
    }

    public static function remove(string $dir): void
    {
        exec('rm -rf ' . escapeshellarg($dir));
    }

    /** The git the command uses, pointed at the fixture repositories. */
    public static function git(string $base): Git
    {
        return new Git(['GIT_CONFIG_GLOBAL' => $base . '/gitconfig', 'GIT_ALLOW_PROTOCOL' => 'https:file']);
    }

    /** git, for building fixtures. */
    public static function run(string $cwd, string ...$args): string
    {
        $process = proc_open(
            array_merge(['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.com', '-c', 'init.defaultBranch=main', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false'], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            ['PATH' => (string) getenv('PATH'), 'HOME' => sys_get_temp_dir(), 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'],
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new \RuntimeException("git {$args[0]} failed: {$err}");
        }

        return trim($out);
    }

    /**
     * A module repository at repos/acme/notes, holding the fixture module at
     * `$path`, optionally changed by `$edit($dir)` before the commit.
     *
     * @return string the commit
     */
    public static function source(string $base, string $path = '.', ?callable $edit = null): string
    {
        $repo = $base . '/repos/acme/notes';
        is_dir($repo) || mkdir($repo, 0o777, true);
        is_dir($repo . '/.git') || self::run($repo, 'init', '--quiet');
        $dir = $path === '.' ? $repo : $repo . '/' . $path;
        is_dir($dir) || mkdir($dir, 0o777, true);

        foreach (['MODULE.md', 'MODULE-TEST.md'] as $file) {
            copy(dirname(__DIR__) . '/Support/Fixtures/modules/fixture-notes/' . $file, $dir . '/' . $file);
        }

        if ($edit !== null) {
            $edit($dir);
        }

        self::run($repo, 'add', '-A');
        self::run($repo, 'commit', '--quiet', '--allow-empty', '-m', 'module');

        return self::run($repo, 'rev-parse', 'HEAD');
    }

    /** The hash of the module at `$path` in a commit, the way docs/modules.md says. */
    public static function hash(string $base, string $commit, string $path = '.'): string
    {
        $out = $base . '/hash-' . bin2hex(random_bytes(4));
        mkdir($out);
        exec('git -C ' . escapeshellarg($base . '/repos/acme/notes') . ' archive ' . escapeshellarg($commit) . ($path === '.' ? '' : ' ' . escapeshellarg($path)) . ' | tar -x -C ' . escapeshellarg($out));
        $dir = $path === '.' ? $out : $out . '/' . $path;
        $hash = trim((string) shell_exec('cd ' . escapeshellarg($dir) . ' && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -d" " -f1'));
        self::remove($out);

        return $hash;
    }

    /**
     * One catalogue version entry, reviewed, for the fixture module.
     *
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     */
    public static function entry(string $commit, string $sha256, array $changes = []): array
    {
        return array_replace([
            'waffle' => '>=0.1 <1.0',
            'form' => 'directory',
            'source' => ['repository' => self::SOURCE, 'commit' => $commit, 'path' => '.'],
            'sha256' => $sha256,
            'proven-on' => [],
            'reviewed-by' => 'a-reviewer',
            'listed' => '2026-10-13',
        ], $changes);
    }

    /**
     * Commit an index.json to the catalogue repository (repos/treehousetim/waffle-iron).
     *
     * @param array<string,array<string,mixed>> $versions version => entry, for fixture-notes
     * @param array<string,mixed>|null          $index    the whole index instead
     */
    public static function catalogue(string $base, array $versions, ?array $index = null): void
    {
        $repo = $base . '/repos/treehousetim/waffle-iron';
        is_dir($repo) || mkdir($repo, 0o777, true);
        is_dir($repo . '/.git') || self::run($repo, 'init', '--quiet');
        $index ??= ['schema' => 1, 'modules' => ['fixture-notes' => [
            'title' => 'Fixture notes',
            'summary' => 'The smallest module the format allows.',
            'author' => 'treehousetim',
            'license' => 'MIT',
            'versions' => $versions,
        ]]];
        file_put_contents($repo . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        self::run($repo, 'add', '-A');
        self::run($repo, 'commit', '--quiet', '-m', 'catalogue');
    }

    /** An app: the four files that mark one, on Waffle `$waffle`. */
    public static function app(string $base, string $waffle = '0.1.0'): string
    {
        $app = $base . '/app';
        mkdir($app . '/tools', 0o777, true);
        file_put_contents($app . '/VERSION', "3\n");
        file_put_contents($app . '/CHANGELOG.md', "# Changelog\n\nStarted from [treehousetim/waffle v{$waffle}](https://github.com/treehousetim/waffle/releases/tag/v{$waffle}).\n\n## [Unreleased]\n");
        file_put_contents($app . '/AGENTS.md', "# AGENTS.md\n\n## 2. Stack\n\n| HTTP client (PHP) | Guzzle, `guzzlehttp/guzzle` ^8.1 |\n\n## 3. Directory layout\n\nmentions some/unapproved here, outside section 2\n");
        file_put_contents($app . '/composer.json', "{}\n");

        return $app;
    }

    /**
     * The command, run in `$app`, with the fixture catalogue and a transport
     * that serves `$downloads` (url => [status, location, body]).
     *
     * @param resource                                            $out
     * @param array<string,array{0:int,1:?string,2:string}>|null  $downloads
     */
    public static function command(string $base, string $app, mixed $out, ?Transport $transport = null): Command
    {
        $git = self::git($base);

        return new Command(
            $app,
            $git,
            $transport ?? new FakeTransport([]),
            new Iron($app . '/.waffle/iron', $git, self::IRON),
            new DateTimeImmutable('2026-10-14', new DateTimeZone('UTC')),
            $out,
        );
    }
}
