<?php

declare(strict_types=1);

/*
 * The deploy scripts, executed rather than read: a shell script that only
 * runs on the server fails there, at release time, unless something runs it
 * first.
 */

/**
 * @param list<string> $args
 *
 * @return array{0:int,1:string,2:string} [exit code, stdout, stderr]
 */
function runScript(string $script, array $args = []): array
{
    $root = dirname(__DIR__, 2);
    $command = 'bash ' . escapeshellarg($root . '/' . $script);

    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

it('parses every shell script without a syntax error', function (string $script): void {
    $process = proc_open(['bash', '-n', dirname(__DIR__, 2) . '/' . $script], [2 => ['pipe', 'w']], $pipes);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $stderr);
})->with([
    'deploy/deploy.sh',
    'deploy/render-crontab.sh',
    'deploy/check-dns.sh',
    'tools/test.sh',
    'tools/changed-groups.sh',
    'tools/backup-prod-db.sh',
    'tools/rename-project.sh',
    'tools/setup.sh',
    'tools/setup-github.sh',
    'tools/release.sh',
    'tools/npm.sh',
    'tools/composer.sh',
]);

it('renders the crontab with the header file and no heartbeat', function (): void {
    [$code, $out] = runScript('deploy/render-crontab.sh', ['deploy/crontab', '/opt/app/.deploy/cron-header']);

    expect($code)->toBe(0)
        ->and($out)->toContain('curl -sf -H @/opt/app/.deploy/cron-header http://127.0.0.1:8081/cron/health >/dev/null 2>&1' . "\n")
        ->and($out)->not->toContain('__CRON_HEADER_FILE__')
        ->and($out)->not->toContain('__HEARTBEAT__');
});

it('adds the heartbeat ping after the health check when there is one', function (): void {
    [$code, $out] = runScript(
        'deploy/render-crontab.sh',
        ['deploy/crontab', '/h', 'https://hc-ping.com/abc-123'],
    );

    expect($code)->toBe(0)
        ->and($out)->toContain('/cron/health >/dev/null 2>&1 && curl -fsS -m 10 -o /dev/null https://hc-ping.com/abc-123');
});

it('refuses a heartbeat URL that could be shell in a crontab', function (string $url): void {
    [$code, , $err] = runScript('deploy/render-crontab.sh', ['deploy/crontab', '/h', $url]);

    expect($code)->toBe(1)
        ->and($err)->toContain('not a plain https URL');
})->with([
    'plain http' => ['http://hc-ping.com/abc'],
    'a quote' => ["https://x.example/'; rm -rf /"],
    'a percent, which cron reads as a newline' => ['https://x.example/a%0A'],
]);

it('calls only cron routes that exist', function (): void {
    preg_match_all('#http://127\.0\.0\.1:8081(/cron/[a-z0-9-]+)#', (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/crontab'), $called);

    $registered = [];

    foreach (Tests\TestCase::app()->getRouteCollector()->getRoutes() as $route) {
        $registered[] = $route->getPattern();
    }

    expect($called[1])->not->toBeEmpty()
        ->and(array_diff($called[1], $registered))->toBe([]);
});

/*
 * The DNS preflight (docs/https.md, failure list 1-6). `dig`, `hostname` and
 * `curl` are shims first on PATH, answering from the environment, so nothing
 * here touches the network.
 */

/**
 * @param array<string,string> $env FAKE_A, FAKE_AAAA, FAKE_LOCAL, FAKE_RESERVED, or overrides
 *
 * @return array{0:int,1:string,2:string,3:string} [exit code, stdout, stderr, dig's arguments]
 */
function checkDns(string $host, array $env = []): array
{
    $bin = sys_get_temp_dir() . '/waffle-dns-' . bin2hex(random_bytes(6));
    mkdir($bin);

    file_put_contents($bin . '/dig', <<<'SH'
        #!/bin/sh
        echo "$*" >> "$(dirname "$0")/dig.args"
        case "$*" in
            *AAAA*) printf '%b' "${FAKE_AAAA:-}" ;;
            *)      printf '%b' "${FAKE_A:-}" ;;
        esac
        SH);
    file_put_contents($bin . '/hostname', "#!/bin/sh\necho \"\${FAKE_LOCAL:-}\"\n");
    file_put_contents($bin . '/curl', "#!/bin/sh\n[ -n \"\${FAKE_RESERVED:-}\" ] || exit 22\nprintf '%s' \"\$FAKE_RESERVED\"\n");

    foreach (['dig', 'hostname', 'curl'] as $shim) {
        chmod($bin . '/' . $shim, 0o755);
    }

    $process = proc_open(
        ['bash', dirname(__DIR__, 2) . '/deploy/check-dns.sh', ...($host === '' ? [] : [$host])],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_merge(['PATH' => $bin . ':' . getenv('PATH')], $env),
    );
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $args = is_file($bin . '/dig.args') ? (string) file_get_contents($bin . '/dig.args') : '';

    exec('rm -rf ' . escapeshellarg($bin));

    return [$code, $stdout, $stderr, $args];
}

it('passes when the A record points at this server', function (): void {
    [$code, $out] = checkDns('ops.acme.com', ['FAKE_A' => "203.0.113.10\n", 'FAKE_LOCAL' => '203.0.113.10 10.0.0.5 172.17.0.1']);

    expect($code)->toBe(0)
        ->and($out)->toContain('ops.acme.com points at this server');
});

it('refuses a hostname with no A record (1)', function (): void {
    [$code, , $err] = checkDns('ops.acme.com', ['FAKE_LOCAL' => '203.0.113.10']);

    expect($code)->toBe(1)
        ->and($err)->toContain('no A record')
        ->and($err)->toContain('203.0.113.10');
});

it('refuses an A record that points somewhere else, and shows both addresses (2)', function (): void {
    [$code, , $err] = checkDns('ops.acme.com', ['FAKE_A' => "198.51.100.7\n", 'FAKE_LOCAL' => '203.0.113.10']);

    expect($code)->toBe(1)
        ->and($err)->toContain('198.51.100.7')
        ->and($err)->toContain('203.0.113.10');
});

it('refuses an AAAA record for an address this server does not have (3)', function (): void {
    [$code, , $err] = checkDns('ops.acme.com', ['FAKE_A' => "203.0.113.10\n", 'FAKE_AAAA' => "2001:db8::1\n", 'FAKE_LOCAL' => '203.0.113.10']);

    expect($code)->toBe(1)
        ->and($err)->toContain('AAAA')
        ->and($err)->toContain('2001:db8::1');
});

it('accepts an AAAA record for an address this server has', function (): void {
    [$code] = checkDns('ops.acme.com', ['FAKE_A' => "203.0.113.10\n", 'FAKE_AAAA' => "2001:db8::1\n", 'FAKE_LOCAL' => '203.0.113.10 2001:db8::1']);

    expect($code)->toBe(0);
});

it('accepts a reserved IP, which is on no interface (4)', function (): void {
    [$code] = checkDns('ops.acme.com', ['FAKE_A' => "192.0.2.50\n", 'FAKE_LOCAL' => '203.0.113.10', 'FAKE_RESERVED' => '192.0.2.50']);

    expect($code)->toBe(0);
});

it('follows a CNAME to the addresses behind it', function (): void {
    [$code] = checkDns('ops.acme.com', ['FAKE_A' => "lb.acme.net.\n203.0.113.10\n", 'FAKE_LOCAL' => '203.0.113.10']);

    expect($code)->toBe(0);
});

it('asks a public resolver, not this machine\'s hosts file or cache (5)', function (): void {
    [, , , $args] = checkDns('ops.acme.com', ['FAKE_A' => "203.0.113.10\n", 'FAKE_LOCAL' => '203.0.113.10']);

    expect($args)->toContain('@1.1.1.1')
        ->and($args)->toContain('A ops.acme.com')
        ->and($args)->toContain('AAAA ops.acme.com');
});

it('fails, rather than passing on nothing, when dig is missing (6)', function (): void {
    [$code, , $err] = checkDns('ops.acme.com', ['DIG' => '/nonexistent/dig', 'FAKE_LOCAL' => '203.0.113.10']);

    expect($code)->toBe(1)
        ->and($err)->toContain('dig');
});

it('needs a hostname', function (): void {
    [$code, , $err] = checkDns('');

    expect($code)->toBe(2)
        ->and($err)->toContain('usage');
});
