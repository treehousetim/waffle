<?php

declare(strict_types=1);

/**
 * Builds the container and the Slim application.
 *
 * Returns a closure so that src/public/index.php and tests/TestCase.php boot
 * the application through exactly the same code path. The optional $overrides
 * argument is how the test suite swaps in a test PDO and fake ports; nothing in
 * production ever passes it.
 *
 * @return Closure(array<string,mixed>): Slim\App
 */

use DI\Bridge\Slim\Bridge;
use Psr\Container\ContainerInterface;
use Slim\App;

return static function (array $overrides = []): App {
    // No .env, anywhere. In production secrets are injected into the container's
    // environment by `doppler run -- docker compose ...` (locally the compose
    // defaults are enough); container.php reads them from
    // $_ENV / getenv(). Nothing is loaded from disk, so nothing sensitive is at
    // rest and there is no stale-file failure mode.
    //
    // The test suite populates $_ENV itself from .env.testing (a committed
    // fixture holding no real secrets) before calling this.

    $build = require __DIR__ . '/container-builder.php';
    $container = $build($overrides);

    $app = Bridge::create($container);

    /** @var Closure(App, ContainerInterface): void $middleware */
    $middleware = require __DIR__ . '/middleware.php';
    $middleware($app, $container);

    /** @var Closure(App, ContainerInterface): void $routes */
    $routes = require __DIR__ . '/routes.php';
    $routes($app, $container);

    return $app;
};
