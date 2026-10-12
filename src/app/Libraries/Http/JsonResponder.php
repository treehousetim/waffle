<?php

declare(strict_types=1);

namespace App\Libraries\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The single place the API response envelope is written.
 *
 * Success: {"success": true,  "data": ...}
 * Failure: {"success": false, "error": "...", "code": "..."}
 *
 * `code` is optional and only present where the frontend has to act on *which*
 * failure it was rather than display it. Matching on the message would mean
 * rewording an error could silently change behaviour.
 *
 * Controllers write into the response Slim handed them (via BaseController);
 * middleware has no response yet and creates one from the factory.
 */
final readonly class JsonResponder
{
    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function write(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    public function success(mixed $data = null, int $status = 200): ResponseInterface
    {
        return $this->write($this->responseFactory->createResponse(), ['success' => true, 'data' => $data], $status);
    }

    public function error(string $message, int $status = 400, ?string $code = null): ResponseInterface
    {
        $payload = ['success' => false, 'error' => $message];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return $this->write($this->responseFactory->createResponse(), $payload, $status);
    }
}
