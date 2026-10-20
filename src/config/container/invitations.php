<?php

declare(strict_types=1);

/**
 * The invitations slice (docs/invitations.md): how people join a closed app.
 */

use App\Controllers\InvitationController;
use App\Controllers\InviteController;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Invitations\Invitations;
use App\Models\InvitationRepository;
use App\Models\TypeRepository;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    InvitationRepository::class => factory(
        static fn (PDO $pdo, TypeRepository $types): InvitationRepository => new InvitationRepository($pdo, $types)
    ),

    Invitations::class => factory(static fn (ContainerInterface $c): Invitations => new Invitations(
        $c->get(InvitationRepository::class),
        $c->get(IdentityPort::class),
        $c->get(ClockPort::class),
    )),

    InvitationController::class => factory(static fn (ContainerInterface $c): InvitationController => new InvitationController(
        $c->get(JsonResponder::class),
        $c->get(Invitations::class),
        $c->get(InvitationRepository::class),
        $c->get(IdentityPort::class),
        $c->get(ClockPort::class),
    )),

    InviteController::class => factory(static fn (ContainerInterface $c): InviteController => new InviteController(
        $c->get(JsonResponder::class),
        $c->get(Invitations::class),
        $c->get(IdentityPort::class),
    )),
];
