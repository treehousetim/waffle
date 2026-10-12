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
    'tools/test.sh',
    'tools/changed-groups.sh',
    'tools/backup-prod-db.sh',
    'tools/rename-project.sh',
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
