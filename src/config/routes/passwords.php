<?php

declare(strict_types=1);

/**
 * The passwords slice's routes (docs/password-reset.md): open to anyone; the
 * emailed link is the permission.
 */

use App\Controllers\PasswordResetController;
use Psr\Container\ContainerInterface;
use Slim\App;

return static function (App $app, ContainerInterface $container): void {
    $app->post('/api/auth/password/forgot', [PasswordResetController::class, 'forgot']);
    $app->get('/api/password-reset/{token}', [PasswordResetController::class, 'show']);
    $app->post('/api/password-reset/{token}', [PasswordResetController::class, 'reset']);
};
