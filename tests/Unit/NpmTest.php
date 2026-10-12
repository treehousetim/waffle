<?php

declare(strict_types=1);

/*
 * tools/npm.sh and the rules around it (docs/supply-chain.md, whose failure
 * list the numbers cite). `docker` is a stand-in first on PATH that records
 * each container it is asked to start, one argument per line, so the tests
 * read exactly what npm would have run with.
 */

/**
 * @param list<string> $args
 * @param array<string,string> $env
 *
 * @return array{code:int,out:string,err:string,runs:list<list<string>>}
 */
function npmScript(array $args, array $env = []): array
{
    $bin = sys_get_temp_dir() . '/waffle-npm-' . bin2hex(random_bytes(6));
    mkdir($bin);

    file_put_contents($bin . '/docker', <<<'SH'
        #!/usr/bin/env bash
        log="$(dirname "$0")"
        n=$(( $(ls "$log" | grep -c '^run\.') + 1 ))
        for arg in "$@"; do printf '%s\n' "$arg"; done > "$log/run.$n"
        SH);
    chmod($bin . '/docker', 0o755);

    $process = proc_open(
        array_merge(['bash', dirname(__DIR__, 2) . '/tools/npm.sh'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2),
        array_merge(['PATH' => $bin . ':' . getenv('PATH'), 'NO_COLOR' => '1', 'NPM_TODAY' => '2026-10-12', 'HOME' => '/home/someone'], $env),
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    $runs = [];
    for ($n = 1; is_file("{$bin}/run.{$n}"); ++$n) {
        $runs[] = array_values(array_filter(explode("\n", (string) file_get_contents("{$bin}/run.{$n}")), static fn (string $l): bool => $l !== ''));
    }

    exec('rm -rf ' . escapeshellarg($bin));

    return ['code' => $code, 'out' => $out, 'err' => $err, 'runs' => $runs];
}

/**
 * The container that runs npm: the last one started.
 *
 * @param array{runs:list<list<string>>} $run
 *
 * @return list<string>
 */
function npmContainer(array $run): array
{
    return $run['runs'] === [] ? [] : $run['runs'][count($run['runs']) - 1];
}

/**
 * What npm itself was given: everything after the image.
 *
 * @param list<string> $container
 *
 * @return list<string>
 */
function npmArgs(array $container): array
{
    $at = array_search('node:26-alpine', $container, true);

    return $at === false ? [] : array_slice($container, (int) $at + 1);
}

it('holds back every version younger than a week when resolving (1)', function (array $command): void {
    $run = npmScript($command);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(npmArgs(npmContainer($run)))->toBe(array_merge(['npm'], $command, ['--before=2026-10-05']));
})->with([
    'install' => [['install']],
    'install a package' => [['install', 'left-pad']],
    'i' => [['i', 'left-pad']],
    'add' => [['add', 'left-pad']],
    'update' => [['update']],
    'up' => [['up']],
    'upgrade' => [['upgrade']],
]);

it('leaves commands that resolve nothing alone', function (array $command): void {
    $run = npmScript($command);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(npmArgs(npmContainer($run)))->toBe(array_merge(['npm'], $command));
})->with([
    'ci' => [['ci']],
    'run build' => [['run', 'build']],
    'outdated' => [['outdated']],
    'audit signatures' => [['audit', 'signatures']],
]);

it('takes the window from NPM_COOLDOWN_DAYS', function (): void {
    $run = npmScript(['update'], ['NPM_COOLDOWN_DAYS' => '3']);

    expect(npmArgs(npmContainer($run)))->toContain('--before=2026-10-09');
});

it('turns the window off at 0, and says so loudly (5)', function (): void {
    $run = npmScript(['install', 'left-pad@1.3.0'], ['NPM_COOLDOWN_DAYS' => '0']);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(npmArgs(npmContainer($run)))->toBe(['npm', 'install', 'left-pad@1.3.0'])
        ->and($run['err'])->toContain('cooldown is OFF');
});

it('refuses a window that is not a number of days (11)', function (string $days): void {
    $run = npmScript(['update'], ['NPM_COOLDOWN_DAYS' => $days]);

    expect($run['code'])->toBe(2)
        ->and($run['err'])->toContain('NPM_COOLDOWN_DAYS')
        ->and($run['runs'])->toBe([]);
    // Not '': proc_open drops an empty variable, which is then unset, which
    // rightly means the default.
})->with(['seven', '-1', '7d', '1.5', ' ']);

it('switches install scripts off in the container and in the project (2)', function (): void {
    $container = npmContainer(npmScript(['ci']));

    expect($container)->toContain('NPM_CONFIG_IGNORE_SCRIPTS=true')
        ->and((string) file_get_contents(dirname(__DIR__, 2) . '/src/frontend/.npmrc'))->toMatch('/^ignore-scripts=true$/m');
});

it('mounts the repository and nothing of the home directory (3)', function (): void {
    $container = npmContainer(npmScript(['ci']));
    $mounts = [];
    foreach ($container as $i => $arg) {
        if ($arg === '-v') {
            $mounts[] = $container[$i + 1];
        }
    }

    expect($mounts)->toContain(dirname(__DIR__, 2) . ':/app')
        ->and(implode(' ', $container))->not->toContain('/home/someone')
        ->and(implode(' ', $container))->not->toContain('.ssh')
        ->and(implode(' ', $container))->not->toContain('DOPPLER')
        ->and($container)->toContain('--rm');
});

it('runs as the invoking user, so nothing it writes is root\'s (8)', function (): void {
    $container = npmContainer(npmScript(['run', 'build']));
    $at = array_search('--user', $container, true);

    expect($at)->not->toBeFalse()
        ->and($container[(int) $at + 1])->toBe(posix_getuid() . ':' . posix_getgid());
});

it('keeps node_modules in a Docker volume, never on the machine\'s disk (9)', function (): void {
    $container = npmContainer(npmScript(['ci']));

    expect($container)->toContain('waffle_node_modules:/app/src/frontend/node_modules');
});

it('publishes the dev server to this machine only, and points its proxy at the stack (10)', function (): void {
    $dev = npmContainer(npmScript(['run', 'dev']));
    $build = npmContainer(npmScript(['run', 'build']));

    expect($dev)->toContain('127.0.0.1:5173:5173')
        ->and($dev)->toContain('VITE_DEV_HOST=0.0.0.0')
        ->and($dev)->toContain('VITE_API_TARGET=http://host.docker.internal:8080')
        ->and($dev)->toContain('host.docker.internal:host-gateway')
        ->and($build)->not->toContain('127.0.0.1:5173:5173');
});

it('follows HTTP_PORT for the dev server\'s proxy (10)', function (): void {
    $dev = npmContainer(npmScript(['run', 'dev'], ['HTTP_PORT' => '8090']));

    expect($dev)->toContain('VITE_API_TARGET=http://host.docker.internal:8090');
});

it('reads the dev server\'s host and proxy target from the environment (10)', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2) . '/src/frontend/vite.config.js');

    expect($config)->toContain('process.env.VITE_DEV_HOST')
        ->and($config)->toContain('process.env.VITE_API_TARGET');
});

it('stops when Docker is missing', function (): void {
    $run = npmScript(['ci'], ['NPM_DOCKER' => '/nonexistent/docker']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('Docker');
});

it('stops on a native Windows shell (12)', function (): void {
    $bin = sys_get_temp_dir() . '/waffle-npm-uname-' . bin2hex(random_bytes(6));
    mkdir($bin);
    file_put_contents($bin . '/uname', "#!/bin/sh\necho MINGW64_NT-10.0\n");
    chmod($bin . '/uname', 0o755);

    $run = npmScript(['ci'], ['PATH' => $bin . ':' . getenv('PATH')]);
    exec('rm -rf ' . escapeshellarg($bin));

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('WSL2')
        ->and($run['runs'])->toBe([]);
});

it('is the only way any document or script tells you to run npm (4)', function (): void {
    /*
     * CI is the one exception: it runs `npm ci` on a runner that holds no
     * secret yet, installing exactly the lockfile (7).
     */
    $root = dirname(__DIR__, 2);
    $files = array_merge(
        [$root . '/README.md', $root . '/AGENTS.md'],
        glob($root . '/docs/*.md') ?: [],
        glob($root . '/tools/*.sh') ?: [],
        glob($root . '/deploy/*.sh') ?: [],
        [$root . '/src/app/Libraries/Http/SpaShell.php'],
    );
    $offenders = [];

    foreach ($files as $file) {
        if (in_array(basename($file), ['npm.sh', 'supply-chain.md'], true)) {
            continue;
        }

        foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
            if (preg_match('#(?<![\w./-])npm (?:install|i|ci|update|up|upgrade|add|run|exec)\b|(?<![\w./-])npx [\w@]#', $line)) {
                $offenders[] = substr($file, strlen($root) + 1) . ':' . ($number + 1) . ': ' . trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('installs exactly the lockfile in CI, and checks its signatures before building (6, 7)', function (): void {
    $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/deploy.yml');

    expect($workflow)->not->toMatch('/^\s+npm (?:install|i|update)\b/m')
        // The step's commands, in this order and nothing between them.
        ->and($workflow)->toMatch('/^( +)npm ci\n\1npm audit signatures\n\1npm run build$/m');
});
