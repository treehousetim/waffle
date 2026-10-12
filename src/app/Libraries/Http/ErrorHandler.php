<?php

declare(strict_types=1);

namespace App\Libraries\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;
use Throwable;

/**
 * Turns every uncaught throwable into the API's error envelope — except an
 * unmatched GET outside the API prefixes, which is an SPA deep link and is
 * answered with the built frontend shell instead.
 */
final readonly class ErrorHandler implements ErrorHandlerInterface
{
    /** Prefixes that are the API, not the SPA. */
    private const array API_PREFIXES = ['/api', '/cron', '/webhooks'];

    public function __construct(
        private JsonResponder $json,
        private SpaShell $spa,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        if ($this->isSpaDeepLink($request, $exception)) {
            return $this->spa->response();
        }

        if ($exception instanceof HttpException) {
            return $this->json->error($exception->getMessage(), $exception->getCode());
        }

        return $this->json->error(
            $displayErrorDetails ? $exception->getMessage() : 'Internal server error.',
            500,
        );
    }

    private function isSpaDeepLink(ServerRequestInterface $request, Throwable $exception): bool
    {
        if (!$exception instanceof HttpNotFoundException || $request->getMethod() !== 'GET') {
            return false;
        }

        $path = $request->getUri()->getPath();

        foreach (self::API_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }
}
