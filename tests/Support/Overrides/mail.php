<?php

declare(strict_types=1);

/**
 * The mail slice, as the test container binds it (docs/mail.md): nothing is
 * sent over the network; every email is recorded.
 */

use App\Domain\Ports\MailPort;
use Tests\Support\Fakes\FakeMailer;

use function DI\factory;

return [
    MailPort::class => factory(static fn (): MailPort => new FakeMailer()),
];
