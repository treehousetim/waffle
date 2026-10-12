<?php

declare(strict_types=1);

namespace App\Libraries\Middleware;

use App\Domain\Auth\Permission;
use App\Domain\Auth\Permissions;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One permission for one route (docs/access-levels.md). Registered once per
 * permission as `can:<value>` in the container, and added to each route inside
 * the group that carries AuthMiddleware, so a stranger still gets 401 first.
 */
final readonly class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private IdentityPort $identity,
        private JsonResponder $json,
        private Permission $permission,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!Permissions::allows($this->identity->role(), $this->permission)) {
            return $this->json->error('Your access level does not include this.', 403);
        }

        return $handler->handle($request);
    }
}
