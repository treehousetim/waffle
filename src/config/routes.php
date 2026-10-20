<?php

declare(strict_types=1);

/**
 * The platform's routes. Feature slices add theirs in src/config/routes/.
 *
 * Groups, in order:
 *   public auth   POST /api/auth/login, POST /api/auth/logout
 *   /api          everything else, behind AuthMiddleware; each route names
 *                 its permission with `can:<permission>`
 *   /cron         behind CronMiddleware
 *   /webhooks     public, each route verifies its provider's signature
 *                 (none yet — add them in a slice's route file)
 *
 * Anything that is not matched here and is not an API path falls through to
 * the error handler, which serves the built SPA shell for GET requests.
 */

use App\Controllers\AuthController;
use App\Controllers\CronController;
use App\Controllers\HelloController;
use App\Domain\Auth\Permission;
use App\Libraries\Middleware\AuthMiddleware;
use App\Libraries\Middleware\Deferred;
use App\Libraries\Middleware\CronMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, ContainerInterface $container): void {
    // -- public ------------------------------------------------------------
    $app->post('/api/auth/login', [AuthController::class, 'login']);
    $app->post('/api/auth/logout', [AuthController::class, 'logout']);

    // -- authenticated -----------------------------------------------------
    // Each route names the permission it needs (docs/access-levels.md).
    // Middleware is named or deferred, not built here: it is built when a
    // route that uses it runs, so a request that never needs the session
    // (the site API) never constructs Delight\Auth, which would start one.
    $can = static fn (Permission $permission): Deferred => new Deferred($container, 'can:' . $permission->value);

    $app->group('/api', function (RouteCollectorProxy $api) use ($can): void {
        // Who am I: any signed-in session, whatever its level.
        $api->get('/auth/me', [AuthController::class, 'me']);

        $api->get('/hello', [HelloController::class, 'index'])->add($can(Permission::Home));
    })->add(AuthMiddleware::class);

    // -- cron --------------------------------------------------------------
    $app->group('/cron', function (RouteCollectorProxy $cron): void {
        $cron->get('/health', [CronController::class, 'health']);
    })->add(CronMiddleware::class);

    /*
     * Per-slice routes. Each feature slice owns one file in src/config/routes/
     * and declares its own groups and middleware there; Slim is happy with
     * several groups sharing a prefix, each with its own stack.
     */
    foreach (glob(__DIR__ . '/routes/*.php') ?: [] as $fragment) {
        /** @var Closure(App, ContainerInterface): void $register */
        $register = require $fragment;
        $register($app, $container);
    }
};
