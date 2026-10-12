<?php

declare(strict_types=1);

/*
 * tools/setup-github.sh (docs/fresh-install.md, steps 13-14 and the appendix
 * failure list, whose numbers these cite). `gh` and `doppler` are stand-ins
 * first on PATH: they answer from the environment and log every call, so
 * nothing here reaches GitHub or Doppler.
 */

const FAKE_TOKEN = 'dp.st.prod-deploy.FAKETOKEN0123456789';

/**
 * @param list<string> $args
 * @param array<string,string> $env FAKE_* answers for the stand-ins
 *
 * @return array{code:int,out:string,err:string,gh:list<string>,doppler:list<string>,stdin:?string}
 */
function setupGithub(array $args = [], array $env = []): array
{
    $bin = sys_get_temp_dir() . '/waffle-gh-' . bin2hex(random_bytes(6));
    mkdir($bin);

    file_put_contents($bin . '/gh', <<<'SH'
        #!/usr/bin/env bash
        log="$(dirname "$0")"
        printf '%s\n' "$*" >> "$log/gh.log"
        case "$1 $2" in
            "auth status") exit "${FAKE_GH_AUTH_EXIT:-0}" ;;
            "repo view")   [ -n "${FAKE_GH_REPO:-}" ] || exit 1; echo "$FAKE_GH_REPO" ;;
            "secret list") printf '%b' "${FAKE_GH_SECRETS:-}" ;;
            "secret set")  cat > "$log/secret.stdin" ;;
            # A write (PUT or POST): swallow whatever JSON it sends.
            "api -X")      cat >/dev/null ;;
            # The one read: the environment's deployment rules, as "type name".
            *)             printf '%b' "${FAKE_GH_POLICIES:-}" ;;
        esac
        SH);

    file_put_contents($bin . '/doppler', <<<'SH'
        #!/usr/bin/env bash
        log="$(dirname "$0")"
        printf '%s\n' "$*" >> "$log/doppler.log"
        case "$1" in
            me)      exit "${FAKE_DOPPLER_AUTH_EXIT:-0}" ;;
            configs)
                case "$2" in
                    get)    exit "${FAKE_DOPPLER_CONFIG_EXIT:-0}" ;;
                    tokens) echo "dp.st.prod-deploy.FAKETOKEN0123456789" ;;
                esac ;;
            secrets)
                printf '{'
                sep=''
                for name in DOPPLER_CONFIG DOPPLER_ENVIRONMENT DOPPLER_PROJECT ${FAKE_DEPLOY_KEYS-DROPLET_HOST DROPLET_USER DROPLET_SSH_KEY DROPLET_SSH_KNOWN_HOSTS}; do
                    printf '%s"%s":{}' "$sep" "$name"; sep=','
                done
                printf '}\n' ;;
        esac
        SH);

    chmod($bin . '/gh', 0o755);
    chmod($bin . '/doppler', 0o755);

    $process = proc_open(
        array_merge(['bash', dirname(__DIR__, 2) . '/tools/setup-github.sh'], $args),
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__, 2),
        array_merge(['PATH' => $bin . ':' . getenv('PATH'), 'NO_COLOR' => '1', 'FAKE_GH_REPO' => 'acme/ops'], $env),
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    $lines = static fn (string $file): array => is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
    $result = [
        'code' => $code,
        'out' => $out,
        'err' => $err,
        'gh' => $lines($bin . '/gh.log'),
        'doppler' => $lines($bin . '/doppler.log'),
        'stdin' => is_file($bin . '/secret.stdin') ? (string) file_get_contents($bin . '/secret.stdin') : null,
    ];

    exec('rm -rf ' . escapeshellarg($bin));

    return $result;
}

/**
 * @param list<string> $calls
 */
function called(array $calls, string $needle): bool
{
    foreach ($calls as $call) {
        if (str_contains($call, $needle)) {
            return true;
        }
    }

    return false;
}

it('creates the tag-gated environment and pipes a read-only token into it (10, 11)', function (): void {
    $run = setupGithub();

    expect($run['code'])->toBe(0, $run['err'])
        ->and(called($run['gh'], 'api -X PUT repos/acme/ops/environments/prod'))->toBeTrue()
        ->and(called($run['gh'], 'api -X POST repos/acme/ops/environments/prod/deployment-branch-policies -f name=v* -f type=tag'))->toBeTrue()
        ->and(called($run['doppler'], 'configs tokens create'))->toBeTrue()
        ->and(called($run['doppler'], '--access read'))->toBeTrue()
        ->and(called($run['gh'], 'secret set DOPPLER_TOKEN --env prod --repo acme/ops'))->toBeTrue()
        // Through the pipe, never as an argument and never printed.
        ->and(trim((string) $run['stdin']))->toBe(FAKE_TOKEN)
        ->and(implode("\n", $run['gh']))->not->toContain(FAKE_TOKEN)
        ->and($run['out'] . $run['err'])->not->toContain(FAKE_TOKEN);
});

it('names the Doppler project after the composer package, unless told otherwise', function (): void {
    $default = setupGithub();
    $named = setupGithub(['--project', 'acme']);

    expect(called($default['doppler'], 'configs tokens create'))->toBeTrue()
        ->and(called($default['doppler'], '--project waffle --config prod-deploy'))->toBeTrue()
        ->and(called($named['doppler'], '--project acme --config prod-deploy'))->toBeTrue();
});

it('stops before changing anything when gh is not signed in (1)', function (): void {
    $run = setupGithub([], ['FAKE_GH_AUTH_EXIT' => '1']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('gh auth login')
        ->and(called($run['gh'], 'api'))->toBeFalse()
        ->and(called($run['doppler'], 'tokens create'))->toBeFalse();
});

it('stops when a tool is not installed at all (1, 2)', function (string $tool, string $variable): void {
    $run = setupGithub([], [$variable => '/nonexistent/' . $tool]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain($tool);
})->with([
    'gh' => ['gh', 'SETUP_GH'],
    'doppler' => ['doppler', 'SETUP_DOPPLER'],
]);

it('stops before changing anything when doppler is not signed in (2)', function (): void {
    $run = setupGithub([], ['FAKE_DOPPLER_AUTH_EXIT' => '1']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('doppler login')
        ->and(called($run['gh'], 'api'))->toBeFalse();
});

it('refuses to guess the repository (3)', function (): void {
    $run = setupGithub([], ['FAKE_GH_REPO' => '']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('--repo')
        ->and(called($run['gh'], 'api'))->toBeFalse();
});

it('uses --repo when given', function (): void {
    $run = setupGithub(['--repo', 'someone/else'], ['FAKE_GH_REPO' => '']);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(called($run['gh'], 'secret set DOPPLER_TOKEN --env prod --repo someone/else'))->toBeTrue();
});

it('issues no token when prod-deploy does not exist (4)', function (): void {
    $run = setupGithub([], ['FAKE_DOPPLER_CONFIG_EXIT' => '1']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('prod-deploy')
        ->and(called($run['doppler'], 'tokens create'))->toBeFalse()
        ->and($run['stdin'])->toBeNull();
});

it('refuses a prod-deploy that holds anything but deploy credentials (5)', function (): void {
    $run = setupGithub([], ['FAKE_DEPLOY_KEYS' => 'DROPLET_HOST DROPLET_USER DROPLET_SSH_KEY DROPLET_SSH_KNOWN_HOSTS DB_PASSWORD CRON_SECRET']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('DB_PASSWORD')
        ->and($run['err'])->toContain('CRON_SECRET')
        ->and(called($run['doppler'], 'tokens create'))->toBeFalse()
        ->and($run['stdin'])->toBeNull();
});

it('accepts the optional alert webhook in prod-deploy', function (): void {
    $run = setupGithub([], ['FAKE_DEPLOY_KEYS' => 'DROPLET_HOST DROPLET_USER DROPLET_SSH_KEY DROPLET_SSH_KNOWN_HOSTS DEPLOY_ALERT_WEBHOOK']);

    expect($run['code'])->toBe(0, $run['err']);
});

it('warns about a missing deploy credential, and carries on (6)', function (): void {
    $run = setupGithub([], ['FAKE_DEPLOY_KEYS' => 'DROPLET_HOST DROPLET_USER']);

    expect($run['code'])->toBe(0, $run['err'])
        ->and($run['out'] . $run['err'])->toContain('DROPLET_SSH_KEY')
        ->and($run['out'] . $run['err'])->toContain('DROPLET_SSH_KNOWN_HOSTS')
        ->and(trim((string) $run['stdin']))->toBe(FAKE_TOKEN);
});

it('refuses an environment that lets anything but v* tags deploy (7)', function (string $policies, string $named): void {
    $run = setupGithub([], ['FAKE_GH_POLICIES' => $policies]);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain($named)
        ->and(called($run['doppler'], 'tokens create'))->toBeFalse();
})->with([
    'a branch' => ["tag v*\nbranch main\n", 'main'],
    'another tag pattern' => ["tag *\n", '*'],
]);

it('adds the v* rule only once (8)', function (): void {
    $run = setupGithub([], ['FAKE_GH_POLICIES' => "tag v*\n"]);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(called($run['gh'], 'deployment-branch-policies -f name='))->toBeFalse();
});

it('leaves an existing token alone unless told to rotate it (9)', function (): void {
    $kept = setupGithub([], ['FAKE_GH_SECRETS' => "DOPPLER_TOKEN\n"]);
    $rotated = setupGithub(['--rotate-token'], ['FAKE_GH_SECRETS' => "DOPPLER_TOKEN\n"]);

    expect($kept['code'])->toBe(0, $kept['err'])
        ->and($kept['out'])->toContain('--rotate-token')
        ->and(called($kept['doppler'], 'tokens create'))->toBeFalse()
        ->and($kept['stdin'])->toBeNull()
        ->and($rotated['code'])->toBe(0, $rotated['err'])
        ->and(trim((string) $rotated['stdin']))->toBe(FAKE_TOKEN);
});

it('rejects an option it does not know', function (): void {
    $run = setupGithub(['--rotate']);

    expect($run['code'])->toBe(2)
        ->and($run['err'])->toContain('unknown option: --rotate');
});
