<?php

declare(strict_types=1);

/**
 * The queue's worker process (docs/queue.md): the `worker` service in both
 * Compose files runs this.
 *
 * It claims and runs jobs until it has run its share or used its memory, then
 * exits for Compose to start a clean one. A stop signal (a deploy, `docker
 * compose stop`) lets the job in hand finish and starts no other. A lost database
 * connection ends the run with a non-zero exit after a pause, and Compose
 * restarts the worker with a new connection.
 */

use App\Libraries\Queue\Worker;

require __DIR__ . '/../../vendor/autoload.php';

/** @var Closure(array<string,mixed>): Psr\Container\ContainerInterface $build */
$build = require __DIR__ . '/../config/container-builder.php';

$worker = $build()->get(Worker::class);

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    // SIGQUIT too: it is the php:fpm image's STOPSIGNAL, which a container
    // built from it inherits unless Compose says otherwise.
    foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
        pcntl_signal($signal, static fn () => $worker->stop());
    }
}

try {
    $ran = $worker->run();
    fwrite(STDOUT, "worker: ran {$ran} job(s); exiting for a fresh process\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'worker: ' . $e::class . ': ' . $e->getMessage() . "\n");
    // Do not restart in a tight loop while the database is away.
    sleep(5);
    exit(1);
}
