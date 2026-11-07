<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domain\Mail\Message;
use App\Domain\Ports\MailPort;
use RuntimeException;

/** The provider, as the tests see it: every email it was handed, or a failure on demand. */
final class FakeMailer implements MailPort
{
    /** @var list<array{message:Message,key:string}> */
    public array $sent = [];

    public bool $down = false;

    public function send(Message $message, string $key): void
    {
        if ($this->down) {
            throw new RuntimeException('The mail provider could not be reached.');
        }

        $this->sent[] = ['message' => $message, 'key' => $key];
    }
}
