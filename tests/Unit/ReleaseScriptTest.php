<?php

declare(strict_types=1);

/*
 * tools/release.sh (docs/releasing.md, whose failure list the numbers cite).
 * Each test gets a scratch repository with a bare repository as its `origin`,
 * a stand-in `gh` that logs its calls, and RELEASE_TEST_CMD standing in for
 * the coverage gate. Nothing leaves the temporary directory.
 */

/**
 * @return array<string,string> the environment every git and script call gets
 */
function releaseEnv(string $base): array
{
    return [
        'PATH' => $base . '/bin:' . getenv('PATH'),
        'HOME' => $base,
        'NO_COLOR' => '1',
        'GIT_AUTHOR_NAME' => 'Test',
        'GIT_AUTHOR_EMAIL' => 'test@example.test',
        'GIT_COMMITTER_NAME' => 'Test',
        'GIT_COMMITTER_EMAIL' => 'test@example.test',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'RELEASE_TEST_CMD' => 'true',
    ];
}

/**
 * @param list<string> $command
 * @param array<string,string> $env
 *
 * @return array{0:int,1:string,2:string}
 */
function releaseExec(array $command, string $cwd, array $env): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $err];
}

/**
 * A repository at $version whose changelog has $unreleased under
 * [Unreleased], committed and pushed to its own bare origin.
 *
 * @return array{base:string,dir:string,env:array<string,string>}
 */
function releaseRepo(string $version, string $unreleased = "### Added\n- A thing.\n"): array
{
    $base = sys_get_temp_dir() . '/waffle-release-' . bin2hex(random_bytes(6));
    $dir = $base . '/work';
    mkdir($base . '/bin', 0o777, true);
    mkdir($dir . '/tools', 0o777, true);
    $env = releaseEnv($base);

    file_put_contents($base . '/bin/gh', <<<'SH'
        #!/usr/bin/env bash
        log="$(dirname "$0")/.."
        printf '%s\n' "$*" >> "$log/gh.log"
        case "$1 $2" in
            "auth status") exit "${FAKE_GH_AUTH_EXIT:-0}" ;;
            "release create")
                while [ "$#" -gt 0 ]; do
                    [ "$1" = "--notes-file" ] && cp "$2" "$log/notes.md"
                    shift
                done ;;
        esac
        SH);
    chmod($base . '/bin/gh', 0o755);

    copy(dirname(__DIR__, 2) . '/tools/release.sh', $dir . '/tools/release.sh');
    file_put_contents($dir . '/VERSION', $version . "\n");
    $previous = $version === '0' ? '' : "## [{$version}] - 2026-01-01\n\n### Fixed\n- Old.\n";
    file_put_contents($dir . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n{$unreleased}\n{$previous}");

    foreach ([
        ['git', 'init', '-q', '--bare', '-b', 'main', $base . '/origin.git'],
        ['git', 'init', '-q', '-b', 'main', $dir],
        ['git', '-C', $dir, 'remote', 'add', 'origin', $base . '/origin.git'],
        ['git', '-C', $dir, 'add', '-A'],
        ['git', '-C', $dir, 'commit', '-q', '-m', 'Start'],
        ['git', '-C', $dir, 'push', '-q', 'origin', 'main'],
    ] as $command) {
        releaseExec($command, $base, $env);
    }

    return ['base' => $base, 'dir' => $dir, 'env' => $env];
}

/**
 * @param array{base:string,dir:string,env:array<string,string>} $repo
 * @param list<string> $args
 * @param array<string,string> $env
 *
 * @return array{code:int,out:string,err:string,gh:string,notes:?string}
 */
function runRelease(array $repo, array $args = [], array $env = []): array
{
    [$code, $out, $err] = releaseExec(
        array_merge(['bash', $repo['dir'] . '/tools/release.sh'], $args),
        $repo['dir'],
        array_merge($repo['env'], $env),
    );

    return [
        'code' => $code,
        'out' => $out,
        'err' => $err,
        'gh' => (string) @file_get_contents($repo['base'] . '/gh.log'),
        'notes' => is_file($repo['base'] . '/notes.md') ? (string) file_get_contents($repo['base'] . '/notes.md') : null,
    ];
}

/**
 * @param array{base:string,dir:string,env:array<string,string>} $repo
 */
function repoGit(array $repo, string ...$args): string
{
    return trim(releaseExec(array_merge(['git', '-C', $repo['dir']], $args), $repo['dir'], $repo['env'])[1]);
}

/**
 * Everything a refused or rehearsed release must leave exactly as it was.
 *
 * @param array{base:string,dir:string,env:array<string,string>} $repo
 *
 * @return array<string,string>
 */
function repoState(array $repo): array
{
    return [
        'head' => repoGit($repo, 'rev-parse', 'HEAD'),
        'status' => repoGit($repo, 'status', '--porcelain'),
        'tags' => repoGit($repo, 'tag'),
        'origin' => repoGit($repo, 'ls-remote', 'origin'),
        'version' => (string) file_get_contents($repo['dir'] . '/VERSION'),
        'changelog' => (string) file_get_contents($repo['dir'] . '/CHANGELOG.md'),
    ];
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir() . '/waffle-release-*') ?: [] as $base) {
        exec('rm -rf ' . escapeshellarg($base));
    }
});

it('releases an app as the next number: version, changelog, commit, tag, push, notes (9, 10)', function (): void {
    $repo = releaseRepo('7');

    $run = runRelease($repo);
    $today = gmdate('Y-m-d');

    expect($run['code'])->toBe(0, $run['err'])
        ->and(file_get_contents($repo['dir'] . '/VERSION'))->toBe("8\n")
        ->and(file_get_contents($repo['dir'] . '/CHANGELOG.md'))
        ->toBe("# Changelog\n\n## [Unreleased]\n\n## [8] - {$today}\n\n### Added\n- A thing.\n\n## [7] - 2026-01-01\n\n### Fixed\n- Old.\n")
        ->and(repoGit($repo, 'log', '-1', '--format=%s'))->toBe('Release v8')
        ->and(repoGit($repo, 'status', '--porcelain'))->toBe('')
        // Both landed on origin, and they agree.
        ->and(repoGit($repo, 'ls-remote', 'origin', 'refs/heads/main'))->toStartWith(repoGit($repo, 'rev-parse', 'HEAD'))
        ->and(repoGit($repo, 'ls-remote', 'origin', 'refs/tags/v8^{}'))->toStartWith(repoGit($repo, 'rev-parse', 'HEAD'))
        ->and($run['gh'])->toContain('release create v8')
        ->and(trim((string) $run['notes']))->toBe("### Added\n- A thing.");
});

it('works out the next version (7)', function (string $version, array $args, string $next): void {
    $repo = releaseRepo($version);

    $run = runRelease($repo, array_merge(['--print-next'], $args));

    expect($run['code'])->toBe(0, $run['err'])
        ->and(trim($run['out']))->toBe($next);
})->with([
    'an app, never released' => ['0', [], '1'],
    'an app' => ['7', [], '8'],
    'an app, past nine' => ['9', [], '10'],
    'the framework, patch by default' => ['0.4.2', [], '0.4.3'],
    'the framework, --patch' => ['0.4.2', ['--patch'], '0.4.3'],
    'the framework, --minor' => ['0.4.2', ['--minor'], '0.5.0'],
    'the framework, --major' => ['0.4.2', ['--major'], '1.0.0'],
    'the framework, two-digit parts' => ['1.19.9', [], '1.19.10'],
]);

it('refuses a semver bump for an app (7)', function (string $flag): void {
    $repo = releaseRepo('7');

    $run = runRelease($repo, ['--print-next', $flag]);

    expect($run['code'])->toBe(2)
        ->and($run['err'])->toContain('one number');
})->with(['--patch', '--minor', '--major']);

it('refuses a VERSION it cannot read (8)', function (string $version): void {
    $repo = releaseRepo('7');
    file_put_contents($repo['dir'] . '/VERSION', $version);

    $run = runRelease($repo, ['--print-next']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('VERSION');
})->with(['v7', '1.2', '01', '1.2.3.4', 'seven', '']);

it('refuses to release from anywhere but main (1)', function (): void {
    $repo = releaseRepo('7');
    repoGit($repo, 'checkout', '-q', '-b', 'feature');
    $before = repoState($repo);

    $run = runRelease($repo);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('main')
        ->and(repoState($repo))->toBe($before);
});

it('refuses uncommitted changes (2)', function (): void {
    $repo = releaseRepo('7');
    file_put_contents($repo['dir'] . '/stray.txt', 'work in progress');
    $before = repoState($repo);

    $run = runRelease($repo);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('uncommitted')
        ->and(repoState($repo))->toBe($before);
});

it('refuses a main that is ahead of origin (3)', function (): void {
    $repo = releaseRepo('7');
    file_put_contents($repo['dir'] . '/extra.txt', 'x');
    repoGit($repo, 'add', '-A');
    repoGit($repo, 'commit', '-q', '-m', 'Not pushed');
    $before = repoState($repo);

    $run = runRelease($repo);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('origin/main')
        ->and(repoState($repo))->toBe($before);
});

it('refuses a main that is behind origin (3)', function (): void {
    $repo = releaseRepo('7');
    $other = $repo['base'] . '/other';
    releaseExec(['git', 'clone', '-q', $repo['base'] . '/origin.git', $other], $repo['base'], $repo['env']);
    file_put_contents($other . '/theirs.txt', 'x');
    releaseExec(['git', '-C', $other, 'add', '-A'], $other, $repo['env']);
    releaseExec(['git', '-C', $other, 'commit', '-q', '-m', 'Theirs'], $other, $repo['env']);
    releaseExec(['git', '-C', $other, 'push', '-q', 'origin', 'main'], $other, $repo['env']);
    $head = repoGit($repo, 'rev-parse', 'HEAD');

    $run = runRelease($repo);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('origin/main')
        ->and(repoGit($repo, 'rev-parse', 'HEAD'))->toBe($head)
        ->and(repoGit($repo, 'tag'))->toBe('');
});

it('refuses a release with nothing under [Unreleased] (4)', function (): void {
    $repo = releaseRepo('7', '');
    $before = repoState($repo);

    $run = runRelease($repo);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('[Unreleased]')
        ->and(repoState($repo))->toBe($before);
});

it('refuses a tag that already exists, here or on origin (5)', function (bool $pushed): void {
    $repo = releaseRepo('7');
    repoGit($repo, 'tag', 'v8');
    if ($pushed) {
        repoGit($repo, 'push', '-q', 'origin', 'v8');
        repoGit($repo, 'tag', '-d', 'v8');
    }
    $before = repoState($repo);

    $run = runRelease($repo);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('v8')
        ->and(repoState($repo))->toBe($before);
})->with(['here' => [false], 'on origin' => [true]]);

it('leaves nothing behind when the gate is red (6)', function (): void {
    $repo = releaseRepo('7');
    $before = repoState($repo);

    $run = runRelease($repo, [], ['RELEASE_TEST_CMD' => 'false']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('tests')
        ->and(repoState($repo))->toBe($before)
        ->and($run['gh'])->not->toContain('release create');
});

it('stops before changing anything when gh is not signed in (11)', function (): void {
    $repo = releaseRepo('7');
    $before = repoState($repo);

    $run = runRelease($repo, [], ['FAKE_GH_AUTH_EXIT' => '1']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('gh auth login')
        ->and(repoState($repo))->toBe($before);
});

it('changes nothing on a dry run, and says what it would do (12)', function (): void {
    $repo = releaseRepo('7');
    $before = repoState($repo);

    $run = runRelease($repo, ['--dry-run']);

    expect($run['code'])->toBe(0, $run['err'])
        ->and($run['out'])->toContain('v8')
        ->and($run['out'])->toContain('- A thing.')
        ->and(repoState($repo))->toBe($before)
        ->and($run['gh'])->not->toContain('release create');
});

it('rejects an option it does not know', function (): void {
    $repo = releaseRepo('7');

    $run = runRelease($repo, ['--minr']);

    expect($run['code'])->toBe(2)
        ->and($run['err'])->toContain('unknown option: --minr');
});
