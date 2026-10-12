<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Controllers\BaseController;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Exposes BaseController's protected helpers so they can be tested directly
 * rather than only through whichever controller happens to use them.
 */
final class ExampleController extends BaseController
{
    public function success(ResponseInterface $response, mixed $data = null, int $status = 200): ResponseInterface
    {
        return $this->jsonSuccess($response, $data, $status);
    }

    public function failure(ResponseInterface $response, string $message, int $status = 400): ResponseInterface
    {
        return $this->jsonError($response, $message, $status);
    }

    /**
     * @return array<string,mixed>
     */
    public function parsedBody(ServerRequestInterface $request): array
    {
        return $this->body($request);
    }

    /**
     * @param array<string,mixed> $source
     */
    public function stringValue(array $source, string $key, string $default = ''): string
    {
        return $this->string($source, $key, $default);
    }

    /**
     * @param array<string,mixed> $source
     */
    public function intValue(array $source, string $key, int $default, int $min, int $max): int
    {
        return $this->int($source, $key, $default, $min, $max);
    }
}
