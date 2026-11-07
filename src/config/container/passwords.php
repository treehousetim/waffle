<?php

declare(strict_types=1);

/**
 * The passwords slice (docs/password-reset.md): resetting a forgotten
 * password by an emailed link.
 */

use App\Controllers\PasswordResetController;
use App\Domain\Mail\MailSettings;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Domain\Ports\MailPort;
use App\Domain\Ports\QueuePort;
use App\Libraries\Auth\PasswordResetMailHandler;
use App\Libraries\Auth\PasswordResets;
use App\Libraries\Http\JsonResponder;
use App\Models\PasswordResetRepository;
use App\Models\UserRepository;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    PasswordResetRepository::class => factory(static fn (PDO $pdo): PasswordResetRepository => new PasswordResetRepository($pdo)),

    PasswordResets::class => factory(static fn (ContainerInterface $c): PasswordResets => new PasswordResets(
        $c->get(PasswordResetRepository::class),
        $c->get(UserRepository::class),
        $c->get(IdentityPort::class),
        $c->get(ClockPort::class),
        $c->get(QueuePort::class),
        $c->get(MailSettings::class),
    )),

    'job:password-reset-mail' => factory(static fn (ContainerInterface $c): PasswordResetMailHandler => new PasswordResetMailHandler(
        $c->get(PasswordResets::class),
        $c->get(MailPort::class),
        $c->get(MailSettings::class),
    )),

    PasswordResetController::class => factory(static fn (ContainerInterface $c): PasswordResetController => new PasswordResetController(
        $c->get(JsonResponder::class),
        $c->get(PasswordResets::class),
    )),
];
