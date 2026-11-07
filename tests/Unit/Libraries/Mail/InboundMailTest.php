<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Domain\Forms\SubmissionStatus;
use App\Domain\Mail\InboundMail;
use App\Domain\Mail\InvalidSignature;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\SendMail;
use App\Libraries\Mail\Inbound;
use App\Libraries\Mail\InboundMailHandler;
use App\Models\Forms\SubmissionRepository;
use App\Models\UserRepository;
use App\Models\WebhookDeliveryRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fakes\FakeReceivedMail;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Svix;
use Tests\TestCase;

/* Mail sent to the domain, on the real tables (docs/mail.md, 13.–17.). */

beforeEach(function (): void {
    $this->clock = new FrozenClock();
    $this->queue = new FakeQueue();
    $this->received = new FakeReceivedMail();
    $settings = new MailSettings('Waffle <noreply@waffle.example.com>', 'https://waffle.example.com', 'Waffle');
    $this->submissions = TestCase::container()->get(SubmissionRepository::class);
    $this->inbound = new Inbound(Svix::SECRET, TestCase::container()->get(WebhookDeliveryRepository::class), $this->queue, $this->clock);
    $this->handler = new InboundMailHandler($this->received, $this->submissions, TestCase::container()->get(UserRepository::class), $this->queue, $settings, $this->clock);
    $this->now = $this->clock->now()->getTimestamp();
});

function deliver(object $test, string $id, string $body): void
{
    $test->inbound->receive($id, (string) $test->now, Svix::sign(Svix::SECRET, $id, $test->now, $body), $body);
}

it('queues the email of a delivery Resend signed, once, however often it is sent (13, 14)', function (): void {
    $body = '{"type":"email.received","data":{"email_id":"em_1"}}';

    deliver($this, 'msg_once', $body);
    deliver($this, 'msg_once', $body);

    expect($this->queue->enqueued)->toHaveCount(1)
        ->and($this->queue->enqueued[0]['job']->payload())->toBe(['email_id' => 'em_1']);
});

it('refuses a delivery it cannot trust, and queues nothing (13)', function (): void {
    expect(fn () => $this->inbound->receive('msg_bad', (string) $this->now, 'v1,AAAA', '{}'))->toThrow(InvalidSignature::class)
        ->and($this->queue->enqueued)->toBe([]);
});

it('records other events and does nothing with them, nor with an email event with no usable id', function (): void {
    deliver($this, 'msg_sent', '{"type":"email.sent","data":{"email_id":"em_2"}}');
    deliver($this, 'msg_noid', '{"type":"email.received","data":{}}');
    deliver($this, 'msg_badid', '{"type":"email.received","data":{"email_id":"../x"}}');
    deliver($this, 'msg_notjson', 'not json');

    expect($this->queue->enqueued)->toBe([]);
});

it('files a received email in the Inbox once, and tells whoever may read it, a reply going to the sender (14)', function (): void {
    Factory::createUser(Role::Owner, 'reader@example.test');
    $this->received->emails['em_3'] = ['from' => 'ada@example.test', 'to' => ['hello@waffle.example.com'], 'subject' => 'Parking?', 'text' => 'Is there parking?', 'headers' => ['from' => 'Ada <ada@example.test>']];

    $this->handler->handle(InboundMail::of('em_3'));
    $this->handler->handle(InboundMail::of('em_3'));

    $filed = array_values(array_filter($this->submissions->withStatus(SubmissionStatus::New), static fn ($s): bool => ($s->payload['email_id'] ?? null) === 'em_3'));
    $notices = array_values(array_filter(array_map(static fn (array $q) => $q['job'], $this->queue->enqueued), static fn ($job): bool => $job instanceof SendMail));
    $toReader = array_values(array_filter($notices, static fn (SendMail $job): bool => $job->message->to === ['reader@example.test']));

    expect($this->handler->jobClass())->toBe(InboundMail::class)
        ->and($filed)->toHaveCount(1)
        ->and($filed[0]->form)->toBe('email')
        ->and($filed[0]->siteId)->toBeNull()
        ->and($filed[0]->payload)->toMatchArray(['name' => 'Ada', 'email' => 'ada@example.test', 'subject' => 'Parking?', 'message' => 'Is there parking?'])
        ->and($toReader)->toHaveCount(1)
        ->and($toReader[0]->message->replyTo)->toBe('ada@example.test')
        ->and($toReader[0]->message->subject)->toBe('Email to hello@waffle.example.com: Parking?')
        ->and($toReader[0]->message->text)->toContain('Is there parking?');
});

it('fails when the message cannot be fetched, so the queue tries again (17)', function (): void {
    $this->received->down = true;

    expect(fn () => $this->handler->handle(InboundMail::of('em_4')))->toThrow(RuntimeException::class);
});

it('files nothing for a message with no usable sender, and does not fail (16)', function (): void {
    $this->received->emails['em_5'] = ['from' => 'not an address', 'to' => [], 'subject' => 'x', 'text' => 'x'];

    $this->handler->handle(InboundMail::of('em_5'));

    expect($this->submissions->hasEmail('em_5'))->toBeFalse()
        ->and($this->queue->enqueued)->toBe([]);
});

it('without a receiving key, refuses to fetch, so the job waits on the Queue page (17)', function (): void {
    expect(fn () => (new App\Libraries\Mail\NoReceivedMail())->fetch('em_6'))->toThrow(RuntimeException::class, 'RESEND_RECEIVING_KEY');
});

it('takes nothing from a site through the Email form (15)', function (): void {
    expect(fn () => (new App\Domain\Forms\EmailForm())->parse(['email' => 'a@example.test']))->toThrow(App\Domain\Forms\InvalidForm::class);
});
