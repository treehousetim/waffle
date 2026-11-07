<?php

declare(strict_types=1);

/**
 * The mail slice's routes (docs/mail.md): Resend's webhook, public and
 * checked by its signature.
 */

use App\Controllers\WebhookController;
use Psr\Container\ContainerInterface;
use Slim\App;

return static function (App $app, ContainerInterface $container): void {
    $app->post('/webhooks/resend', [WebhookController::class, 'resend']);
};
