<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Invitations\InvalidInvitation;
use App\Domain\Invitations\InvitationRules;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Domain\Status\IllegalTransition;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Invitations\InvitationNotFound;
use App\Libraries\Invitations\Invitations;
use App\Models\Invitation;
use App\Models\InvitationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Inviting people (docs/invitations.md), behind `invitations-manage` (7.):
 * the list, a new invitation with its link's token this once, revoking.
 */
final class InvitationController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly Invitations $invitations,
        private readonly InvitationRepository $repository,
        private readonly IdentityPort $identity,
        private readonly ClockPort $clock,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $now = $this->now();

        return $this->jsonSuccess($response, [
            'invitations' => array_map(static fn (Invitation $invitation): array => $invitation->toArray($now), $this->repository->all()),
            'levels' => array_map(
                static fn ($role): array => ['value' => $role->value, 'label' => $role->label()],
                InvitationRules::levelsFor($this->identity->role()),
            ),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        try {
            [$invitation, $token] = $this->invitations->invite((int) $this->identity->id(), $this->identity->role(), $body['email'] ?? null, $body['level'] ?? null);
        } catch (InvalidInvitation $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        }

        return $this->jsonSuccess($response, ['invitation' => $invitation->toArray($this->now()), 'token' => $token], 201);
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        try {
            $invitation = $this->invitations->revoke($number === false ? 0 : $number);
        } catch (InvitationNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (IllegalTransition) {
            return $this->jsonError($response, 'Only an invitation that is still waiting can be revoked.', 409);
        }

        return $this->jsonSuccess($response, ['invitation' => $invitation->toArray($this->now())]);
    }

    private function now(): string
    {
        return InvitationRepository::time($this->clock->now());
    }
}
