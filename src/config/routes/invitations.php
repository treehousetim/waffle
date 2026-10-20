<?php

declare(strict_types=1);

/**
 * The invitations slice's routes (docs/invitations.md).
 */

use App\Controllers\InvitationController;
use App\Controllers\InviteController;
use App\Domain\Auth\Permission;
use App\Libraries\Middleware\AuthMiddleware;
use App\Libraries\Middleware\Deferred;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, ContainerInterface $container): void {
    $can = static fn (Permission $permission): Deferred => new Deferred($container, 'can:' . $permission->value);

    $app->group('/api/invitations', function (RouteCollectorProxy $invitations) use ($can): void {
        $invitations->get('', [InvitationController::class, 'index'])->add($can(Permission::InvitationsManage));
        $invitations->post('', [InvitationController::class, 'create'])->add($can(Permission::InvitationsManage));
        $invitations->post('/{id}/revoke', [InvitationController::class, 'revoke'])->add($can(Permission::InvitationsManage));
    })->add(AuthMiddleware::class);

    // The link itself: no account, the token is the permission.
    $app->get('/api/invite/{token}', [InviteController::class, 'show']);
    $app->post('/api/invite/{token}/accept', [InviteController::class, 'accept']);
};
