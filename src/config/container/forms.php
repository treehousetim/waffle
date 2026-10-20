<?php

declare(strict_types=1);

/**
 * Forms (docs/forms.md): the sites that send them and what they send. A form
 * is a `form:<name>` definition here (or in a slice's fragment), gathered in
 * container.php, with its `types` row in the group `form`.
 */

use App\Controllers\FormsCronController;
use App\Controllers\InboxController;
use App\Controllers\SiteController;
use App\Controllers\SiteFormController;
use App\Domain\Forms\ContactForm;
use App\Domain\Forms\FormDefinition;
use App\Domain\Forms\FormDefinitions;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Forms\Reception;
use App\Libraries\Forms\Sites;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Middleware\SiteKeyMiddleware;
use App\Models\Forms\SiteRepository;
use App\Models\Forms\SubmissionRepository;
use App\Models\SettingRepository;
use App\Models\TypeRepository;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    'form:contact' => factory(static fn (): FormDefinition => new FormDefinition(
        'contact',
        'Contact',
        'Thanks, we have your message and will reply soon.',
        new ContactForm(),
    )),

    // Every `form:<name>`, gathered in container.php.
    FormDefinitions::class => factory(static fn (ContainerInterface $c): FormDefinitions => new FormDefinitions(...array_map(
        static fn (string $name): FormDefinition => $c->get('form:' . $name),
        $c->get('forms.names'),
    ))),

    SiteRepository::class => factory(static fn (PDO $pdo, TypeRepository $types): SiteRepository => new SiteRepository($pdo, $types)),
    SubmissionRepository::class => factory(static fn (PDO $pdo, TypeRepository $types): SubmissionRepository => new SubmissionRepository($pdo, $types)),

    Reception::class => factory(static fn (ContainerInterface $c): Reception => new Reception(
        $c->get(FormDefinitions::class),
        $c->get(SubmissionRepository::class),
        $c->get(ClockPort::class),
    )),
    Sites::class => factory(static fn (ContainerInterface $c): Sites => new Sites($c->get(SiteRepository::class), $c->get(ClockPort::class))),

    SiteKeyMiddleware::class => factory(static fn (ContainerInterface $c): SiteKeyMiddleware => new SiteKeyMiddleware(
        $c->get(SiteRepository::class),
        $c->get(JsonResponder::class),
    )),

    SiteFormController::class => factory(static fn (ContainerInterface $c): SiteFormController => new SiteFormController(
        $c->get(JsonResponder::class),
        $c->get(Reception::class),
    )),
    InboxController::class => factory(static fn (ContainerInterface $c): InboxController => new InboxController(
        $c->get(JsonResponder::class),
        $c->get(SubmissionRepository::class),
        $c->get(IdentityPort::class),
        $c->get(ClockPort::class),
    )),
    SiteController::class => factory(static fn (ContainerInterface $c): SiteController => new SiteController(
        $c->get(JsonResponder::class),
        $c->get(Sites::class),
        $c->get(SiteRepository::class),
    )),
    FormsCronController::class => factory(static fn (ContainerInterface $c): FormsCronController => new FormsCronController(
        $c->get(JsonResponder::class),
        $c->get(SubmissionRepository::class),
        $c->get(SettingRepository::class),
        $c->get(ClockPort::class),
    )),
];
