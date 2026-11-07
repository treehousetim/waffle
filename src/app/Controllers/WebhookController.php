<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Mail\InvalidSignature;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Mail\Inbound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resend's webhook (docs/mail.md, "Mail sent to the domain"): public, and
 * trusted only by its signature, over the body exactly as it arrived (13.).
 * A delivery sent again answers 200 and does nothing (14.).
 */
final class WebhookController extends BaseController
{
    public function __construct(JsonResponder $json, private readonly Inbound $inbound)
    {
        parent::__construct($json);
    }

    public function resend(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->inbound->receive(
                $request->getHeaderLine('svix-id'),
                $request->getHeaderLine('svix-timestamp'),
                $request->getHeaderLine('svix-signature'),
                (string) $request->getBody(),
            );
        } catch (InvalidSignature $e) {
            return $this->jsonError($response, $e->getMessage(), 401);
        }

        return $this->jsonSuccess($response, ['received' => true]);
    }
}
