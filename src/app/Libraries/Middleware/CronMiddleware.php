<?php

declare(strict_types=1);

namespace App\Libraries\Middleware;

use App\Libraries\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards the /cron endpoints crond calls over localhost.
 *
 * An empty CRON_SECRET denies everything: a misconfigured deploy must fail
 * closed rather than expose the job endpoints to anyone who can reach the host.
 *
 * **The header is the only way to present it.** Never a `?secret=` query
 * parameter: a secret in a URL is a secret in an access log, a proxy log and a
 * referrer, and the whole value of the credential is that it does not turn up
 * in a file somebody greps later.
 */
final readonly class CronMiddleware implements MiddlewareInterface
{
    public function __construct(
        private JsonResponder $json,
        private string $secret,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->secret === '') {
            return $this->json->error('Cron access is not configured.', 403);
        }

        if (!hash_equals($this->secret, $this->presentedSecret($request))) {
            return $this->json->error('Invalid cron secret.', 403);
        }

        return $handler->handle($request);
    }

    private function presentedSecret(ServerRequestInterface $request): string
    {
        return $request->getHeaderLine('X-Cron-Secret');
    }
}
