<?php

declare(strict_types=1);

namespace App\Libraries\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A middleware taken from the container only when a request reaches it.
 *
 * Routes are registered on every request, so a guard built at registration
 * builds Delight\Auth, which starts a session, even for a request that never
 * runs the guard (the site API, docs/content.md). Slim defers class names by
 * itself, but reads `can:home` as a class and method, so the permission
 * guards come through here instead.
 */
final readonly class Deferred implements MiddlewareInterface
{
    public function __construct(
        private ContainerInterface $container,
        private string $id,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var MiddlewareInterface $middleware */
        $middleware = $this->container->get($this->id);

        return $middleware->process($request, $handler);
    }
}
