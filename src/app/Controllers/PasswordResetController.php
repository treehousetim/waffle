<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Auth\InvalidReset;
use App\Libraries\Auth\PasswordResets;
use App\Libraries\Auth\ResetNotFound;
use App\Libraries\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Password reset by an emailed link (docs/password-reset.md). No account
 * needed: asking is open to anyone and says nothing about who has one (1.);
 * the link is the permission.
 */
final class PasswordResetController extends BaseController
{
    public function __construct(JsonResponder $json, private readonly PasswordResets $resets)
    {
        parent::__construct($json);
    }

    public function forgot(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $this->resets->request($this->body($request)['email'] ?? null);
        } catch (InvalidReset $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        }

        return $this->jsonSuccess($response, ['message' => 'If that address has an account, we have emailed it a link.']);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $token): ResponseInterface
    {
        try {
            return $this->jsonSuccess($response, ['email' => $this->resets->open($token)]);
        } catch (ResetNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        }
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response, string $token): ResponseInterface
    {
        try {
            $this->resets->reset($token, $this->body($request)['password'] ?? null);
        } catch (ResetNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (InvalidReset $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        }

        return $this->jsonSuccess($response, ['message' => 'Your password is changed. Sign in with it.']);
    }
}
