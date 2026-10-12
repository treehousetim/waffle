<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * The "inner" handler in a middleware unit test. Records whether the middleware
 * let the request through, so a test can assert that a rejection really did
 * short-circuit rather than merely rewriting the response afterwards.
 */
final class RecordingHandler implements RequestHandlerInterface
{
    public bool $wasCalled = false;

    public ?ServerRequestInterface $receivedRequest = null;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->wasCalled = true;
        $this->receivedRequest = $request;

        $response = (new ResponseFactory())->createResponse(204);
        $response->getBody()->write('passed through');

        return $response;
    }
}
