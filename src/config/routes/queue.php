<?php

declare(strict_types=1);

/**
 * The queue slice's routes (docs/queue.md): the failed jobs for people with
 * `queue-manage`, and the cron check.
 */

use App\Controllers\QueueController;
use App\Controllers\QueueCronController;
use App\Domain\Auth\Permission;
use App\Libraries\Middleware\AuthMiddleware;
use App\Libraries\Middleware\CronMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, ContainerInterface $container): void {
    $can = static fn (Permission $permission): mixed => $container->get('can:' . $permission->value);

    $app->group('/api', function (RouteCollectorProxy $api) use ($can): void {
        $api->get('/queue', [QueueController::class, 'index'])->add($can(Permission::QueueManage));
        $api->post('/queue/jobs/{id}/retry', [QueueController::class, 'retry'])->add($can(Permission::QueueManage));
        $api->delete('/queue/jobs/{id}', [QueueController::class, 'discard'])->add($can(Permission::QueueManage));
    })->add($container->get(AuthMiddleware::class));

    $app->group('/cron', function (RouteCollectorProxy $cron): void {
        $cron->get('/queue', [QueueCronController::class, 'check']);
    })->add($container->get(CronMiddleware::class));
};
