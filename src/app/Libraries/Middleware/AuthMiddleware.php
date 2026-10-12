<?php

declare(strict_types=1);

namespace App\Libraries\Middleware;

use App\Domain\Ports\IdentityPort;
use App\Libraries\Http\JsonResponder;
use App\Models\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rejects anonymous requests with a 401 in the API's error envelope, so the
 * frontend's Axios interceptor can send the person back to sign in.
 */
final readonly class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private IdentityPort $identity,
        private JsonResponder $json,
        private UserRepository $users,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->identity->isAuthenticated()) {
            return $this->json->error('Authentication required.', 401);
        }

        /*
         * An account that has been turned off stops working immediately,
         * including in a tab that is already open.
         *
         * Delight has its own mechanism for this -- force_logout, compared on
         * a resync -- and the container sets that resync to every request.
         * This check is here as well because revocation is the one thing that
         * must not depend on a library's caching behaviour, and because it is
         * testable where the resync is not.
         */
        $id = $this->identity->id();

        if ($id !== null && !$this->users->isEnabled($id)) {
            $this->identity->logout();

            return $this->json->error('This account has been turned off.', 403);
        }

        return $handler->handle($request);
    }
}
