<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared response shaping and request parsing for every Slim handler.
 *
 * Controllers parse the request, call into Domain/ or Models/, and format the
 * result. They contain no business rules of their own.
 */
abstract class BaseController
{
    public function __construct(protected readonly JsonResponder $json)
    {
    }

    /**
     * @param array<string,mixed> $data
     */
    protected function jsonSuccess(ResponseInterface $response, mixed $data = null, int $status = 200): ResponseInterface
    {
        return $this->json->write($response, ['success' => true, 'data' => $data], $status);
    }

    protected function jsonError(ResponseInterface $response, string $message, int $status = 400): ResponseInterface
    {
        return $this->json->write($response, ['success' => false, 'error' => $message], $status);
    }

    /**
     * The parsed request body, always an array.
     *
     * @return array<string,mixed>
     */
    protected function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * A trimmed scalar value from an already-parsed source array.
     *
     * @param array<string,mixed> $source
     */
    protected function string(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? null;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * A bounded positive integer from an already-parsed source array.
     *
     * @param array<string,mixed> $source
     */
    protected function int(array $source, string $key, int $default, int $min, int $max): int
    {
        $value = $source[$key] ?? null;

        if (!is_scalar($value) || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
