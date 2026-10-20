<?php

declare(strict_types=1);

/**
 * The settings slice's routes (docs/settings.md).
 *
 * A slice declares its own group and its own middleware: Slim is happy with
 * several `/api` groups, each carrying AuthMiddleware, so nothing in
 * routes.php had to change for this file to exist.
 */

use App\Controllers\SettingController;
use App\Domain\Auth\Permission;
use App\Libraries\Middleware\AuthMiddleware;
use App\Libraries\Middleware\Deferred;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, ContainerInterface $container): void {
    $can = static fn (Permission $permission): Deferred => new Deferred($container, 'can:' . $permission->value);

    $app->group('/api', function (RouteCollectorProxy $api) use ($can): void {
        $api->get('/settings', [SettingController::class, 'index'])->add($can(Permission::SettingsView));
        $api->put('/settings', [SettingController::class, 'update'])->add($can(Permission::SettingsEdit));
    })->add(AuthMiddleware::class);
};
