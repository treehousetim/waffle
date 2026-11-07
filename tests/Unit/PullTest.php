<?php

declare(strict_types=1);

/*
 * deploy/pull.sh, the server's half of a release (docs/pull-based-deployments.md,
 * failure list 1-20). `curl` and `doppler` are shims first on PATH: GitHub's
 * answers come from files in a scratch directory, and nothing here touches the
 * network. `jq`, `flock`, `tar` and `sha256sum` are the real ones, as on the
 * server. The release's own deploy/deploy.sh is a stand-in that records the tag
 * and switches `current`, as the real one does at the end of a good deploy.
 */

const PULL_TOKEN = 'ghp-test-token-123';

/** @return array{root:string,fake:string,shims:string} */
function pullFixture(): array
{
    $base = sys_get_temp_dir() . '/waffle-pull-' . bin2hex(random_bytes(6));
    $fixture = ['root' => $base . '/opt', 'fake' => $base . '/fake', 'shims' => $base . '/shims'];

    foreach ($fixture as $dir) {
        mkdir($dir, 0o755, true);
    }

    mkdir($fixture['root'] . '/releases');
    mkdir($fixture['root'] . '/shared/deploy', 0o755, true);

    file_put_contents($fixture['shims'] . '/curl', <<<'SH'
        #!/usr/bin/env bash
        printf '%s\n' "$*" >> "$SHIMS/curl.args"
        out=""; fmt=""; data=""; url=""
        while [ $# -gt 0 ]; do
            case "$1" in
                -K) [ "$2" = - ] && url="$(sed -n 's/^url = "\(.*\)"$/\1/p')"; shift 2 ;;
                -o) out="$2"; shift 2 ;;
                -w) fmt="$2"; shift 2 ;;
                -d) data="$2"; shift 2 ;;
                -H|-m) shift 2 ;;
                -*) shift ;;
                *) url="$1"; shift ;;
            esac
        done
        code=200
        case "$url" in
            https://hooks.example/*) printf '%s\n' "$data" >> "$SHIMS/alerts.log"; exit 0 ;;
            */releases/latest|*/releases/tags/*)
                [ -n "${FAKE_API_DOWN:-}" ] && exit 7
                code="${FAKE_API_CODE:-200}"; src="$FAKE/release.json" ;;
            */releases/assets/*) src="$FAKE/asset-${url##*/}" ;;
            *) exit 6 ;;
        esac
        if [ -n "$out" ]; then cat "$src" > "$out" 2>/dev/null || :; else cat "$src" 2>/dev/null || :; fi
        [ -n "$fmt" ] && printf '%s' "$code"
        exit 0
        SH);
    file_put_contents($fixture['shims'] . '/doppler', <<<'SH'
        #!/usr/bin/env bash
        [ -n "${FAKE_DOPPLER_DOWN:-}" ] && exit 1
        var="FAKE_SECRET_$3"
        printf '%s' "${!var:-}"
        SH);
    chmod($fixture['shims'] . '/curl', 0o755);
    chmod($fixture['shims'] . '/doppler', 0o755);

    return $fixture;
}

/**
 * Publishes a release in the fake GitHub: its JSON, and its two assets.
 *
 * @param array{author?:string,uploader?:string,complete?:bool,badSum?:bool,assets?:bool,uploaded?:bool} $options
 */
function pullRelease(array $fixture, string $tag, array $options = []): void
{
    $tree = $fixture['fake'] . '/tree-' . bin2hex(random_bytes(4));
    mkdir($tree . '/deploy', 0o755, true);
    file_put_contents($tree . '/deploy/deploy.sh', <<<'SH'
        #!/usr/bin/env bash
        root="$(cd "$(dirname "$0")/../../.." && pwd)"
        echo "$1" >> "$root/deployed.log"
        [ "${FAKE_DEPLOY_EXIT:-0}" = 0 ] || exit "$FAKE_DEPLOY_EXIT"
        if [ -L "$root/current" ]; then basename "$(readlink "$root/current")" > "$root/shared/deploy/previous-tag"; fi
        ln -sfn "releases/$1" "$root/current"
        SH);

    if ($options['complete'] ?? true) {
        mkdir($tree . '/vendor');
        mkdir($tree . '/src/public/assets', 0o755, true);
        touch($tree . '/vendor/autoload.php');
        touch($tree . '/docker-compose.prod.yml');
    }

    $tarball = $fixture['fake'] . '/asset-1';
    exec('tar -czf ' . escapeshellarg($tarball) . ' -C ' . escapeshellarg($tree) . ' .');
    $sum = ($options['badSum'] ?? false) ? str_repeat('0', 64) : hash_file('sha256', $tarball);
    file_put_contents($fixture['fake'] . '/asset-2', $sum . '  app-' . $tag . ".tar.gz\n");

    $state = ($options['uploaded'] ?? true) ? 'uploaded' : 'open';
    $uploader = ['login' => $options['uploader'] ?? 'github-actions[bot]'];
    $assets = ($options['assets'] ?? true) ? [
        ['id' => 1, 'name' => 'app-' . $tag . '.tar.gz', 'state' => $state, 'uploader' => $uploader, 'url' => 'https://api.example/repos/acme/app/releases/assets/1'],
        ['id' => 2, 'name' => 'app-' . $tag . '.tar.gz.sha256', 'state' => $state, 'uploader' => $uploader, 'url' => 'https://api.example/repos/acme/app/releases/assets/2'],
    ] : [];

    file_put_contents($fixture['fake'] . '/release.json', (string) json_encode([
        'tag_name' => $tag,
        'author' => ['login' => $options['author'] ?? 'treehousetim'],
        'assets' => $assets,
    ]));
}

/** A release already live: on disk, and `current` pointing at it. */
function pullLive(array $fixture, string $tag): void
{
    mkdir($fixture['root'] . '/releases/' . $tag . '/deploy', 0o755, true);
    if (is_link($fixture['root'] . '/current')) {
        unlink($fixture['root'] . '/current');
    }

    symlink('releases/' . $tag, $fixture['root'] . '/current');
}

/**
 * @param list<string>          $args
 * @param array<string,string>  $env
 *
 * @return array{0:int,1:string,2:string}
 */
function pull(array $fixture, array $args = ['check'], array $env = []): array
{
    $process = proc_open(
        ['bash', dirname(__DIR__, 2) . '/deploy/pull.sh', ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_merge([
            'PATH' => $fixture['shims'] . ':' . getenv('PATH'),
            'HOME' => $fixture['root'],
            'APP_ROOT' => $fixture['root'],
            'SLUG' => 'app',
            'REPO' => 'acme/app',
            'DEPLOY_AUTHORS' => 'treehousetim alice',
            'DEPLOY_USER' => trim((string) shell_exec('id -un')),
            'GITHUB_API' => 'https://api.example',
            'FAKE' => $fixture['fake'],
            'SHIMS' => $fixture['shims'],
            'FAKE_SECRET_GITHUB_RELEASES_TOKEN' => PULL_TOKEN,
            'FAKE_SECRET_DEPLOY_ALERT_WEBHOOK' => 'https://hooks.example/alert',
        ], $env),
    );
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function pullAlerts(array $fixture): string
{
    return is_file($fixture['shims'] . '/alerts.log') ? (string) file_get_contents($fixture['shims'] . '/alerts.log') : '';
}

function pullDeployed(array $fixture): string
{
    return is_file($fixture['root'] . '/deployed.log') ? (string) file_get_contents($fixture['root'] . '/deployed.log') : '';
}

function pullCleanup(array $fixture): void
{
    exec('rm -rf ' . escapeshellarg(dirname($fixture['root'])));
}

it('pulls a newer release, checks it, unpacks it and hands it to its own deploy.sh', function (): void {
    $f = pullFixture();
    pullLive($f, '1');
    pullRelease($f, '2');

    [$code, $out] = pull($f);

    expect($code)->toBe(0, $out)
        ->and(pullDeployed($f))->toBe("2\n")
        ->and(is_file($f['root'] . '/releases/2/vendor/autoload.php'))->toBeTrue()
        ->and(readlink($f['root'] . '/current'))->toBe('releases/2')
        ->and(pullAlerts($f))->toBe('');
    pullCleanup($f);
});

it('deploys the first release onto an empty server', function (): void {
    $f = pullFixture();
    pullRelease($f, '1');

    [$code] = pull($f);

    expect($code)->toBe(0)
        ->and(pullDeployed($f))->toBe("1\n");
    pullCleanup($f);
});

it('does nothing, quietly, when the latest release is already live (4)', function (): void {
    $f = pullFixture();
    pullLive($f, '3');
    pullRelease($f, '3');

    [$code] = pull($f);

    expect($code)->toBe(0)
        ->and(pullDeployed($f))->toBe('')
        ->and(pullAlerts($f))->toBe('');
    pullCleanup($f);
});

it('does nothing when no release has been published yet (2)', function (): void {
    $f = pullFixture();

    [$code] = pull($f, ['check'], ['FAKE_API_CODE' => '404']);

    expect($code)->toBe(0)
        ->and(pullDeployed($f))->toBe('');
    pullCleanup($f);
});

it('refuses a tag that is not a release number, says so once, and never repeats the tag (3)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2-<!channel>');

    pull($f);
    [$code] = pull($f);

    expect($code)->toBe(0)
        ->and(pullDeployed($f))->toBe('')
        ->and(substr_count(pullAlerts($f), 'is not a release number'))->toBe(1)
        ->and(pullAlerts($f))->not->toContain('<!channel>');
    pullCleanup($f);
});

it('refuses a release older than the one live, so a re-marked old tag cannot roll back (5)', function (): void {
    $f = pullFixture();
    pullLive($f, '10');
    pullRelease($f, '9');

    pull($f);
    pull($f);

    expect(pullDeployed($f))->toBe('')
        ->and(substr_count(pullAlerts($f), 'older than 10'))->toBe(1);
    pullCleanup($f);
});

it('compares versions as numbers, not text (5)', function (): void {
    $f = pullFixture();
    pullLive($f, '9');
    pullRelease($f, '10');

    pull($f);

    expect(pullDeployed($f))->toBe("10\n");
    pullCleanup($f);
});

it('refuses a release published by someone not approved (6)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['author' => 'mallory']);

    pull($f);
    pull($f);

    expect(pullDeployed($f))->toBe('')
        ->and(substr_count(pullAlerts($f), 'mallory'))->toBe(1);
    pullCleanup($f);
});

it('waits without failing while the assets are still being attached (7)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['assets' => false]);

    [$code] = pull($f);

    expect($code)->toBe(0)
        ->and(pullDeployed($f))->toBe('')
        ->and(pullAlerts($f))->toBe('');
    pullCleanup($f);
});

it('counts an asset still uploading as missing (7)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['uploaded' => false]);

    pull($f);

    expect(pullDeployed($f))->toBe('');
    pullCleanup($f);
});

it('says so, once, when the assets have been missing too long (7)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['assets' => false]);

    pull($f, ['check'], ['ASSET_WAIT_MINUTES' => '0']);
    pull($f, ['check'], ['ASSET_WAIT_MINUTES' => '0']);

    expect(substr_count(pullAlerts($f), 'no tarball'))->toBe(1);
    pullCleanup($f);
});

it('refuses a tarball that does not match its checksum, and keeps nothing of it (8)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['badSum' => true]);

    pull($f);
    pull($f);

    expect(pullDeployed($f))->toBe('')
        ->and(is_dir($f['root'] . '/releases/2'))->toBeFalse()
        ->and(is_dir($f['root'] . '/releases/2.partial'))->toBeFalse()
        ->and(substr_count(pullAlerts($f), 'checksum'))->toBe(1);
    pullCleanup($f);
});

it('refuses a release whose tree is incomplete (9)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['complete' => false]);

    pull($f);

    expect(pullDeployed($f))->toBe('')
        ->and(is_dir($f['root'] . '/releases/2'))->toBeFalse()
        ->and(pullAlerts($f))->toContain('incomplete');
    pullCleanup($f);
});

it('skips a tick while another deploy holds the lock (10)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');
    $holder = proc_open(['flock', $f['root'] . '/shared/deploy/lock', 'sleep', '3'], [], $pipes);
    usleep(300_000);

    [$code] = pull($f);
    proc_close($holder);

    expect($code)->toBe(0)
        ->and(pullDeployed($f))->toBe('');
    pullCleanup($f);
});

it('records a failed deploy, says so, and does not retry it on the next tick (11, 12)', function (): void {
    $f = pullFixture();
    pullLive($f, '1');
    pullRelease($f, '2');

    [$code] = pull($f, ['check'], ['FAKE_DEPLOY_EXIT' => '1']);
    pull($f, ['check'], ['FAKE_DEPLOY_EXIT' => '1']);

    expect($code)->toBe(1)
        ->and(pullDeployed($f))->toBe("2\n")
        ->and(trim((string) file_get_contents($f['root'] . '/shared/deploy/failed-tag')))->toBe('2')
        ->and(pullAlerts($f))->toContain('2 failed')
        ->and(readlink($f['root'] . '/current'))->toBe('releases/1');
    pullCleanup($f);
});

it('tries a failed release again after retry (11)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');
    pull($f, ['check'], ['FAKE_DEPLOY_EXIT' => '1']);

    pull($f, ['retry']);
    pull($f);

    expect(pullDeployed($f))->toBe("2\n2\n")
        ->and(is_file($f['root'] . '/shared/deploy/failed-tag'))->toBeFalse();
    pullCleanup($f);
});

it('reports GitHub being unreachable only once it has lasted (1)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');

    [$quiet] = pull($f, ['check'], ['FAKE_API_DOWN' => '1']);
    $before = pullAlerts($f);
    pull($f, ['check'], ['FAKE_API_DOWN' => '1', 'API_ALERT_MINUTES' => '0']);
    pull($f, ['check'], ['FAKE_API_DOWN' => '1', 'API_ALERT_MINUTES' => '0']);

    expect($quiet)->toBe(1)
        ->and($before)->toBe('')
        ->and(substr_count(pullAlerts($f), 'GitHub'))->toBe(1)
        ->and(pullDeployed($f))->toBe('');
    pullCleanup($f);
});

it('treats an answer it cannot read as GitHub failing, never as nothing to do (1)', function (): void {
    $f = pullFixture();
    file_put_contents($f['fake'] . '/release.json', '<html>rate limited</html>');

    [$code] = pull($f, ['check'], ['API_ALERT_MINUTES' => '0']);

    expect($code)->toBe(1)
        ->and(pullAlerts($f))->toContain('GitHub');
    pullCleanup($f);
});

it('stops, and says so once, without the release token (13)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');

    [$code] = pull($f, ['check'], ['FAKE_SECRET_GITHUB_RELEASES_TOKEN' => '']);
    pull($f, ['check'], ['FAKE_SECRET_GITHUB_RELEASES_TOKEN' => '']);

    expect($code)->toBe(1)
        ->and(pullDeployed($f))->toBe('')
        ->and(substr_count(pullAlerts($f), 'GITHUB_RELEASES_TOKEN'))->toBe(1);
    pullCleanup($f);
});

it('stops when Doppler cannot be read (13)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');

    [$code, , $err] = pull($f, ['check'], ['FAKE_DOPPLER_DOWN' => '1']);

    expect($code)->toBe(1)
        ->and($err)->toContain('GITHUB_RELEASES_TOKEN')
        ->and(pullDeployed($f))->toBe('');
    pullCleanup($f);
});

it('clears a half-unpacked release left by an interrupted run (14)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');
    mkdir($f['root'] . '/releases/2.partial/stale', 0o755, true);

    pull($f);

    expect(is_dir($f['root'] . '/releases/2.partial'))->toBeFalse()
        ->and(is_dir($f['root'] . '/releases/2/stale'))->toBeFalse()
        ->and(pullDeployed($f))->toBe("2\n");
    pullCleanup($f);
});

it('keeps the newest releases, and always the live and previous ones (15)', function (): void {
    $f = pullFixture();

    foreach (['1', '2', '3', '4', '5'] as $tag) {
        mkdir($f['root'] . '/releases/' . $tag);
    }

    file_put_contents($f['root'] . '/shared/deploy/previous-tag', "1\n");
    pullLive($f, '5');
    pullRelease($f, '6');

    pull($f, ['check'], ['KEEP' => '2']);

    $left = array_values(array_diff((array) scandir($f['root'] . '/releases'), ['.', '..']));
    sort($left);

    expect($left)->toBe(['5', '6']);
    pullCleanup($f);
});

it('never puts the release token on a command line or leaves it on disk (16)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');

    pull($f);
    $left = (string) shell_exec('grep -rl ' . escapeshellarg(PULL_TOKEN) . ' ' . escapeshellarg($f['root']) . ' 2>/dev/null');

    expect((string) file_get_contents($f['shims'] . '/curl.args'))->not->toContain(PULL_TOKEN)
        ->and($left)->toBe('');
    pullCleanup($f);
});

it('deploys a named tag on request, older ones included, for a deliberate rollback', function (): void {
    $f = pullFixture();
    pullLive($f, '5');
    pullRelease($f, '4');

    [$code] = pull($f, ['deploy', '4']);

    expect($code)->toBe(0)
        ->and(pullDeployed($f))->toBe("4\n");
    pullCleanup($f);
});

it('holds after a rollback by name: the next tick does not deploy the newer release again (20)', function (): void {
    $f = pullFixture();
    pullRelease($f, '4');
    pull($f);
    pullRelease($f, '5');
    pull($f);

    [$code, $out] = pull($f, ['deploy', '4']);
    pull($f);
    pull($f);
    [, $status] = pull($f, ['status']);

    expect($code)->toBe(0, $out)
        ->and(pullDeployed($f))->toBe("4\n5\n4\n")
        ->and(readlink($f['root'] . '/current'))->toBe('releases/4')
        ->and($status)->toContain('held:     5');
    pullCleanup($f);
});

it('ends the hold when something newer is published, or on retry (20)', function (): void {
    $f = pullFixture();
    pullRelease($f, '4');
    pull($f);
    pullRelease($f, '5');
    pull($f);
    pull($f, ['deploy', '4']);

    pullRelease($f, '6');
    pull($f);
    $afterNewer = readlink($f['root'] . '/current');

    pull($f, ['deploy', '4']);
    pull($f, ['retry']);
    pull($f);

    expect($afterNewer)->toBe('releases/6')
        ->and(readlink($f['root'] . '/current'))->toBe('releases/6');
    pullCleanup($f);
});

it('holds whatever the next check finds after a rollback while GitHub cannot be read (20)', function (): void {
    $f = pullFixture();
    pullRelease($f, '4');
    pull($f);
    pullRelease($f, '5');
    pull($f);

    [$code] = pull($f, ['deploy', '4'], ['FAKE_API_DOWN' => '1']);
    pull($f);
    [, $status] = pull($f, ['status']);

    expect($code)->toBe(0)
        ->and(readlink($f['root'] . '/current'))->toBe('releases/4')
        ->and($status)->toContain('held:     5');
    pullCleanup($f);
});

it('still refuses a named tag that fails its checksum (8)', function (): void {
    $f = pullFixture();
    pullRelease($f, '4', ['badSum' => true]);

    [$code] = pull($f, ['deploy', '4']);

    expect($code)->toBe(1)
        ->and(pullDeployed($f))->toBe('');
    pullCleanup($f);
});

it('shows what is live, what was before, and what failed', function (): void {
    $f = pullFixture();
    pullLive($f, '7');
    file_put_contents($f['root'] . '/shared/deploy/previous-tag', "6\n");
    file_put_contents($f['root'] . '/shared/deploy/failed-tag', "8\n");

    [$code, $out] = pull($f, ['status']);

    expect($code)->toBe(0)
        ->and($out)->toContain('live:     7')
        ->and($out)->toContain('previous: 6')
        ->and($out)->toContain('failed:   8');
    pullCleanup($f);
});

it('rejects a command it does not know', function (): void {
    $f = pullFixture();

    [$code, , $err] = pull($f, ['explode']);

    expect($code)->toBe(2)
        ->and($err)->toContain('usage');
    pullCleanup($f);
});

it('refuses release files that GitHub Actions did not upload, and says so once (17)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['uploader' => 'mallory']);

    pull($f);
    pull($f);

    expect(pullDeployed($f))->toBe('')
        ->and(is_dir($f['root'] . '/releases/2'))->toBeFalse()
        ->and(substr_count(pullAlerts($f), 'not uploaded by github-actions[bot]'))->toBe(1);
    pullCleanup($f);
});

it('refuses to deploy a release on disk that has changed since it was unpacked (18)', function (string $change): void {
    $f = pullFixture();
    pullRelease($f, '2');
    pull($f, ['check'], ['FAKE_DEPLOY_EXIT' => '1']);
    $release = $f['root'] . '/releases/2';
    match ($change) {
        'edited' => file_put_contents($release . '/deploy/deploy.sh', "\necho planted\n", FILE_APPEND),
        'added' => file_put_contents($release . '/tools-planted.sh', "echo planted\n"),
        'removed' => unlink($release . '/vendor/autoload.php'),
    };

    pull($f, ['retry']);
    [$code] = pull($f, ['deploy', '2']);

    expect($code)->toBe(1)
        ->and(pullDeployed($f))->toBe("2\n")
        ->and(pullAlerts($f))->toContain('changed since it was unpacked');
    pullCleanup($f);
})->with(['edited', 'added', 'removed']);

it('refuses to run as anyone but the deploy user (19)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2');

    [$code, , $err] = pull($f, ['check'], ['DEPLOY_USER' => 'someone-else']);

    expect($code)->toBe(1)
        ->and($err)->toContain('someone-else')
        ->and(pullDeployed($f))->toBe('');
    pullCleanup($f);
});

it('never puts the alert webhook on a command line (16)', function (): void {
    $f = pullFixture();
    pullRelease($f, '2', ['author' => 'mallory']);

    pull($f);

    expect(pullAlerts($f))->toContain('mallory')
        ->and((string) file_get_contents($f['shims'] . '/curl.args'))->not->toContain('hooks.example');
    pullCleanup($f);
});

it('refuses a named tag that is not a release number', function (string $tag): void {
    $f = pullFixture();

    [$code] = pull($f, ['deploy', $tag]);

    expect($code)->toBe(2)
        ->and(pullDeployed($f))->toBe('');
    pullCleanup($f);
})->with(['v2', 'main', '2;id', '../2', '0', '02']);
