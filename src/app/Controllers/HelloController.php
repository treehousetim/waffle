<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Ports\IdentityPort;
use App\Libraries\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The authenticated landing endpoint the skeleton proves end to end: session,
 * permission middleware, controller, envelope, frontend.
 *
 * Replace it with the real home page's summary when there is one.
 */
final class HelloController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly IdentityPort $identity,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->jsonSuccess($response, [
            'message' => 'Hello from Waffle.',
            'email' => (string) $this->identity->email(),
        ]);
    }
}
