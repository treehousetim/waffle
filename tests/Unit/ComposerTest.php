<?php

declare(strict_types=1);

/*
 * Composer's supply-chain rules (docs/supply-chain.md, the Composer failure
 * list, whose numbers these cite). The age check is a plain function, tested
 * directly and against the real lock; tools/composer.sh is run in a scratch
 * copy against a stand-in `docker` that logs every call and can play an
 * update that brought something young in.
 */

require_once dirname(__DIR__, 2) . '/tools/composer-age.php';

function composerLockAt(DateTimeImmutable $now): array
{
    return [
        'packages' => [
            ['name' => 'old/package', 'version' => '1.0.0', 'time' => $now->modify('-30 days')->format(DATE_ATOM)],
            ['name' => 'young/package', 'version' => '2.1.0', 'time' => $now->modify('-3 days')->format(DATE_ATOM)],
            ['name' => 'timeless/package', 'version' => '0.1.0'],
        ],
        'packages-dev' => [
            ['name' => 'young/dev-tool', 'version' => '5.0.0', 'time' => $now->modify('-1 day')->format('Y-m-d\TH:i:sP')],
            ['name' => 'edge/package', 'version' => '1.0.0', 'time' => $now->modify('-7 days')->format(DATE_ATOM)],
        ],
    ];
}

it('names every package released within the window, dev ones included (1)', function (): void {
    $now = new DateTimeImmutable('2026-10-12T12:00:00+00:00');

    expect(composerYoungPackages(composerLockAt($now), [], 7, $now))->toBe([
        'young/package 2.1.0 (released 3 days ago)',
        'timeless/package 0.1.0 (no release time recorded)',
        'young/dev-tool 5.0.0 (released 1 day ago)',
    ]);
});

it('treats a package with no release time as young (3)', function (): void {
    $now = new DateTimeImmutable('2026-10-12T12:00:00+00:00');

    expect(composerYoungPackages(['packages' => [['name' => 'a/b', 'version' => '1.0.0']]], [], 7, $now))
        ->toBe(['a/b 1.0.0 (no release time recorded)']);
});

it('honours an exception for exactly the recorded version (4)', function (): void {
    $now = new DateTimeImmutable('2026-10-12T12:00:00+00:00');
    $young = composerYoungPackages(composerLockAt($now), [
        'young/package' => '2.1.0',
        'young/dev-tool' => '4.9.0',
        'timeless/package' => '0.1.0',
    ], 7, $now);

    expect($young)->toBe(['young/dev-tool 5.0.0 (released 1 day ago)']);
});

it('reads the exceptions file, ignoring comments and blank lines (4)', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'cooldown');
    file_put_contents($file, "# why each is here\n\nvendor/one 1.2.3  2026-10-12 urgent fix\n  vendor/two v2.0.0\n# vendor/three 3.0.0\n");

    $exceptions = composerCooldownExceptions($file);
    unlink($file);

    expect($exceptions)->toBe(['vendor/one' => '1.2.3', 'vendor/two' => 'v2.0.0'])
        ->and(composerCooldownExceptions('/nonexistent/file'))->toBe([]);
});

it('holds nothing younger than a week in the real lock, unless recorded (2)', function (): void {
    $root = dirname(__DIR__, 2);
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);

    expect(composerYoungPackages($lock, composerCooldownExceptions($root . '/cooldown-exceptions.txt'), 7, new DateTimeImmutable('now')))
        ->toBe([]);
});

it('allows exactly the plugins in the lock, and never a wildcard (6)', function (): void {
    $root = dirname(__DIR__, 2);
    $allowed = json_decode((string) file_get_contents($root . '/composer.json'), true)['config']['allow-plugins'];
    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
    $plugins = [];
    foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
        if (($package['type'] ?? '') === 'composer-plugin') {
            $plugins[$package['name']] = true;
        }
    }
    ksort($plugins);
    ksort($allowed);

    expect($allowed)->toBe($plugins)
        ->and(array_keys($allowed))->each->not->toContain('*');
});

it('builds production vendor/ in CI with no plugins or scripts, then audits it (7, 8)', function (): void {
    $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/deploy.yml');
    $install = strpos($workflow, 'composer install --no-dev --no-plugins --no-scripts');
    $audit = strpos($workflow, 'composer audit --locked --no-dev');
    // The command, not the comment near the top that mentions it.
    $ship = strpos($workflow, 'rsync -az --delete');

    expect($install)->not->toBeFalse()
        ->and($audit)->not->toBeFalse()
        ->and($ship)->not->toBeFalse()
        ->and($install < $audit && $audit < $ship)->toBeTrue();
});

/**
 * A scratch project holding the script, the age check, and a lock.
 */
function composerFixture(): string
{
    $root = dirname(__DIR__, 2);
    $dir = sys_get_temp_dir() . '/waffle-composer-' . bin2hex(random_bytes(6));
    mkdir($dir . '/tools', 0o777, true);
    mkdir($dir . '/bin');

    foreach (['tools/composer.sh', 'tools/composer-age.php', 'composer.json', 'composer.lock'] as $path) {
        copy($root . '/' . $path, $dir . '/' . $path);
    }

    file_put_contents($dir . '/bin/docker', <<<'SH'
        #!/usr/bin/env bash
        here="$(cd "$(dirname "$0")/.." && pwd)"
        printf '%s\n' "$*" >> "$here/docker.log"
        case "$*" in
            *"php tools/composer-age.php"*)
                printf '%b' "${FAKE_AGE_OUT:-}"
                exit "${FAKE_AGE_EXIT:-0}" ;;
            *" composer require "*|*" composer update"*|*" composer remove "*)
                # Play an update: both files change.
                echo '// changed' >> "$here/composer.lock"
                echo ' ' >> "$here/composer.json" ;;
        esac
        SH);
    chmod($dir . '/bin/docker', 0o755);

    return $dir;
}

/**
 * @param list<string> $args
 * @param array<string,string> $env
 *
 * @return array{code:int,out:string,err:string,calls:list<string>}
 */
function runComposerScript(string $dir, array $args, array $env = []): array
{
    $process = proc_open(
        array_merge(['bash', $dir . '/tools/composer.sh'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
        array_merge(['PATH' => $dir . '/bin:' . getenv('PATH'), 'NO_COLOR' => '1', 'HOME' => '/home/someone'], $env),
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $log = $dir . '/docker.log';

    return [
        'code' => $code,
        'out' => $out,
        'err' => $err,
        'calls' => is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [],
    ];
}

/**
 * @param list<string> $calls
 */
function composerCalled(array $calls, string $needle): bool
{
    foreach ($calls as $call) {
        if (str_contains($call, $needle)) {
            return true;
        }
    }

    return false;
}

beforeEach(function (): void {
    $this->dir = composerFixture();
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

it('runs Composer in the php service, with none of the home directory (9)', function (): void {
    $run = runComposerScript($this->dir, ['install']);

    expect($run['code'])->toBe(0, $run['err'])
        // -T: no terminal here, so no pseudo-terminal either.
        ->and($run['calls'][0] ?? '')->toBe('compose run --rm --no-deps -T php composer install')
        ->and(implode("\n", $run['calls']))->not->toContain('/home/someone');
});

it('hands the files it writes back to the invoking user (10)', function (): void {
    $run = runComposerScript($this->dir, ['install']);

    expect(composerCalled($run['calls'], 'php chown ' . posix_getuid() . ':' . posix_getgid() . ' composer.json composer.lock'))->toBeTrue();
});

it('checks the lock after anything that picks versions (1)', function (array $command): void {
    $run = runComposerScript($this->dir, $command);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(composerCalled($run['calls'], 'php tools/composer-age.php --days 7'))->toBeTrue();
})->with([
    'require' => [['require', 'vendor/package']],
    'update' => [['update']],
    'update one' => [['update', 'vendor/package']],
    'remove' => [['remove', 'vendor/package']],
]);

it('does not check the lock after commands that pick nothing', function (array $command): void {
    $run = runComposerScript($this->dir, $command);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(composerCalled($run['calls'], 'composer-age.php'))->toBeFalse();
})->with([
    'install' => [['install']],
    'audit' => [['audit']],
    'show' => [['show']],
    'update --lock, which only rehashes' => [['update', '--lock']],
]);

it('puts everything back when an update brings in something young (1, 5)', function (): void {
    $json = file_get_contents($this->dir . '/composer.json');
    $lock = file_get_contents($this->dir . '/composer.lock');

    $run = runComposerScript($this->dir, ['update'], [
        'FAKE_AGE_EXIT' => '1',
        'FAKE_AGE_OUT' => "young/package 2.1.0 (released 3 days ago)\n",
    ]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('young/package 2.1.0')
        ->and($run['err'])->toContain('COMPOSER_COOLDOWN_DAYS=0')
        ->and(file_get_contents($this->dir . '/composer.json'))->toBe($json)
        ->and(file_get_contents($this->dir . '/composer.lock'))->toBe($lock)
        // vendor/ follows the restored lock.
        ->and(end($run['calls']))->toContain('php composer install');
});

it('records an urgent fix instead of refusing it, and says so loudly (4)', function (): void {
    $run = runComposerScript($this->dir, ['update', 'vendor/package'], ['COMPOSER_COOLDOWN_DAYS' => '0']);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(composerCalled($run['calls'], 'php tools/composer-age.php --days 7 --record'))->toBeTrue()
        ->and($run['err'])->toContain('cooldown is OFF');
});

it('refuses a window that is not a number of days (11)', function (string $days): void {
    $run = runComposerScript($this->dir, ['update'], ['COMPOSER_COOLDOWN_DAYS' => $days]);

    expect($run['code'])->toBe(2)
        ->and($run['err'])->toContain('COMPOSER_COOLDOWN_DAYS')
        ->and($run['calls'])->toBe([]);
})->with(['seven', '-1', '7d', ' ']);

it('stops when Docker is missing', function (): void {
    $run = runComposerScript($this->dir, ['install'], ['COMPOSER_DOCKER' => '/nonexistent/docker']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('Docker');
});

it('stops on a native Windows shell (12)', function (): void {
    file_put_contents($this->dir . '/bin/uname', "#!/bin/sh\necho MINGW64_NT-10.0\n");
    chmod($this->dir . '/bin/uname', 0o755);

    $run = runComposerScript($this->dir, ['install']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('WSL2')
        ->and($run['calls'])->toBe([]);
});

it('is the only way any document or script tells you to pick Composer versions (2, 9)', function (): void {
    /*
     * Installing from the lock (`composer install`) picks nothing and is fine
     * anywhere; so is `update --lock`, which only rehashes. CI builds from the
     * lock with plugins and scripts off (7).
     */
    $root = dirname(__DIR__, 2);
    $files = array_merge(
        [$root . '/README.md', $root . '/AGENTS.md'],
        glob($root . '/docs/*.md') ?: [],
        glob($root . '/tools/*.sh') ?: [],
        glob($root . '/deploy/*.sh') ?: [],
    );
    $offenders = [];

    foreach ($files as $file) {
        if (in_array(basename($file), ['composer.sh', 'supply-chain.md'], true)) {
            continue;
        }

        foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
            if (preg_match('#(?<![\w./-])composer (?:require|remove|upgrade|u|update(?! --lock))\b#', $line)) {
                $offenders[] = substr($file, strlen($root) + 1) . ':' . ($number + 1) . ': ' . trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});
