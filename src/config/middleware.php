<?php

declare(strict_types=1);

/**
 * Application middleware, outermost last.
 *
 * Slim runs middleware LIFO, so the error middleware is added last in order to
 * wrap everything else — including routing, so a 404 reaches our handler.
 */

use App\Libraries\Http\ErrorHandler;
use App\Libraries\Middleware\SameSiteMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;

return static function (App $app, ContainerInterface $container): void {
    /** @var array{app:array{debug:bool}} $config */
    $config = $container->get('config');

    $app->addBodyParsingMiddleware();

    /*
     * Cross-site refusal, application-wide rather than per group.
     *
     * Global because a defence that has to be remembered in each route group
     * is a defence that will be missing from the next one. It costs the other
     * transports nothing: the decision allows a request that carries neither
     * `Sec-Fetch-Site` nor `Origin`, which is every cron job and webhook, and
     * none of them authenticate with the cookie this protects anyway.
     *
     * Added before routing so it also covers a request that matches no route.
     */
    $app->add($container->get(SameSiteMiddleware::class));

    $app->addRoutingMiddleware();

    $errorMiddleware = $app->addErrorMiddleware(
        $config['app']['debug'],
        true,
        true,
    );

    $errorMiddleware->setDefaultErrorHandler($container->get(ErrorHandler::class));
};
