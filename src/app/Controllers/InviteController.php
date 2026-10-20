<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Invitations\InvalidInvitation;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Invitations\InvitationNotFound;
use App\Libraries\Invitations\Invitations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An invitation link, opened and accepted by the person invited
 * (docs/invitations.md). No account needed: the token is the permission.
 */
final class InviteController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly Invitations $invitations,
        private readonly IdentityPort $identity,
    ) {
        parent::__construct($json);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $token): ResponseInterface
    {
        try {
            $invitation = $this->invitations->open($token);
        } catch (InvitationNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        }

        return $this->jsonSuccess($response, ['email' => $invitation->email, 'level_label' => $invitation->role->label()]);
    }

    /** Makes the account, then signs it in, signing out whoever was (10.). */
    public function accept(ServerRequestInterface $request, ResponseInterface $response, string $token): ResponseInterface
    {
        $body = $this->body($request);

        try {
            $invitation = $this->invitations->accept($token, $body['name'] ?? null, $body['password'] ?? null);
        } catch (InvitationNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (InvalidInvitation $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        }

        $this->identity->login($invitation->email, (string) $body['password']);

        return $this->jsonSuccess($response, ['email' => $invitation->email]);
    }
}
