<?php

declare(strict_types=1);

/**
 * Forms' routes (docs/forms.md).
 */

use App\Controllers\FormsCronController;
use App\Controllers\InboxController;
use App\Controllers\SiteController;
use App\Controllers\SiteFormController;
use App\Domain\Auth\Permission;
use App\Libraries\Middleware\AuthMiddleware;
use App\Libraries\Middleware\CronMiddleware;
use App\Libraries\Middleware\Deferred;
use App\Libraries\Middleware\SiteKeyMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, ContainerInterface $container): void {
    $can = static fn (Permission $permission): Deferred => new Deferred($container, 'can:' . $permission->value);

    // From a site's server, with its key: no session, no account.
    $app->post('/api/site/forms/{name}', [SiteFormController::class, 'receive'])->add(SiteKeyMiddleware::class);

    $app->group('/api', function (RouteCollectorProxy $api) use ($can): void {
        $api->get('/forms/submissions', [InboxController::class, 'index'])->add($can(Permission::FormsManage));
        $api->post('/forms/submissions/{id}/handled', [InboxController::class, 'handled'])->add($can(Permission::FormsManage));
        $api->post('/forms/submissions/{id}/spam', [InboxController::class, 'spam'])->add($can(Permission::FormsManage));

        $api->get('/sites', [SiteController::class, 'index'])->add($can(Permission::SitesManage));
        $api->post('/sites', [SiteController::class, 'create'])->add($can(Permission::SitesManage));
        $api->post('/sites/{id}/key', [SiteController::class, 'issueKey'])->add($can(Permission::SitesManage));
        $api->post('/sites/{id}/disable', [SiteController::class, 'disable'])->add($can(Permission::SitesManage));
        $api->post('/sites/{id}/enable', [SiteController::class, 'enable'])->add($can(Permission::SitesManage));
    })->add(AuthMiddleware::class);

    $app->group('/cron', function (RouteCollectorProxy $cron): void {
        $cron->get('/forms', [FormsCronController::class, 'prune']);
    })->add(CronMiddleware::class);
};
