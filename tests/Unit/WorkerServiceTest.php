<?php

declare(strict_types=1);

/*
 * The queue's worker as a service (docs/queue.md), asserted by shape: it runs
 * in both stacks, restarts, can be stopped gracefully, and is paused by the
 * deploy while migrations run.
 */

function workerFile(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $name);
}

/** One service's section of a compose file. */
function workerService(string $yaml): string
{
    preg_match('/^  worker:\n((?:    .*\n|\n)*)/m', $yaml, $match);

    return $match[1] ?? '';
}

it('runs the worker in both stacks, restarting it when it exits (13)', function (string $file): void {
    $worker = workerService(workerFile($file));

    expect($worker)->toContain('php src/cli/worker.php')
        ->and($worker)->toContain('restart: unless-stopped');
})->with(['docker-compose.yml', 'docker-compose.prod.yml']);

it('gives the worker the php service\'s environment and time to finish a job on stop (14)', function (): void {
    $compose = workerFile('docker-compose.yml');
    $worker = workerService($compose);

    expect($compose)->toContain('environment: &app_environment')
        ->and($worker)->toContain('environment: *app_environment')
        ->and($worker)->toContain('stop_signal: SIGTERM')
        ->and($worker)->toContain('stop_grace_period: 60s');
});

it('runs the worker with production settings in production', function (): void {
    $worker = workerService(workerFile('docker-compose.prod.yml'));

    expect($worker)->toContain('APP_ENV: production')
        ->and($worker)->toContain('PHP_INI: php.prod.ini');
});

it('builds PHP with pcntl, so the worker hears SIGTERM (14)', function (): void {
    expect(workerFile('docker/php/Dockerfile'))->toMatch('/docker-php-ext-install [^\n]*pcntl/')
        // SIGQUIT is the php:fpm image's own stop signal.
        ->and(workerFile('src/cli/worker.php'))->toContain('[SIGTERM, SIGINT, SIGQUIT]');
});

it('stops the worker before migrations and starts it after php restarts (12)', function (): void {
    $deploy = workerFile('deploy/deploy.sh');
    $stopped = strpos($deploy, 'up -d --remove-orphans --scale worker=0');
    $migrated = strpos($deploy, 'vendor/bin/phinx migrate -e production');
    $restarted = strpos($deploy, 'restart php');
    $started = strpos($deploy, 'up -d --no-deps worker');

    expect($stopped)->toBeInt()
        ->and($migrated)->toBeGreaterThan($stopped)
        ->and($restarted)->toBeGreaterThan($migrated)
        ->and($started)->toBeGreaterThan($restarted);
});
