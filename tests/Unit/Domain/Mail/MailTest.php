<?php

declare(strict_types=1);

use App\Domain\Mail\InvalidMessage;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\Message;
use App\Domain\Mail\SendMail;
use App\Domain\Queue\InvalidJobPayload;
use App\Libraries\Mail\NoMailer;
use App\Libraries\Mail\SendMailHandler;
use Tests\Support\Fakes\FakeMailer;
use Tests\Support\Fixtures\Queue\RecordingJob;

/* The mail core (docs/mail.md, whose failure list the numbers cite). */

it('is a plain-text message to one or more addresses, with an optional reply-to (6)', function (): void {
    $message = Message::of(['Ann@Example.test', 'bob@example.test'], 'A subject', "Line one\nLine two", 'reply@example.test');

    expect($message->to)->toBe(['ann@example.test', 'bob@example.test'])
        ->and($message->subject)->toBe('A subject')
        ->and($message->text)->toBe("Line one\nLine two\n")
        ->and($message->replyTo)->toBe('reply@example.test')
        ->and(Message::of('ann@example.test', 'S', 'T')->replyTo)->toBeNull()
        ->and(Message::fromArray($message->toArray()))->toEqual($message)
        ->and(array_keys($message->toArray()))->toBe(['to', 'subject', 'text', 'reply_to']);
});

it('refuses an address that is not one, and a line break or control character in a subject (5)', function (mixed $to, string $subject, ?string $replyTo): void {
    expect(fn () => Message::of($to, $subject, 'text', $replyTo))->toThrow(InvalidMessage::class);
})->with([
    'no recipient' => [[], 'S', null],
    'not an address' => ['not-an-address', 'S', null],
    'a header in an address' => ["a@example.test\r\nBcc: x@example.test", 'S', null],
    'not text' => [[42], 'S', null],
    'a line break in the subject' => ['a@example.test', "Hi\r\nBcc: x@example.test", null],
    'a control character in the subject' => ['a@example.test', "Hi\x07", null],
    'an empty subject' => ['a@example.test', '  ', null],
    'a subject far too long' => ['a@example.test', str_repeat('a', 250), null],
    'a reply-to that is not an address' => ['a@example.test', 'S', 'nope'],
    'too many recipients' => [array_map(static fn (int $i): string => "a{$i}@example.test", range(1, 51)), 'S', null],
]);

it('refuses an empty body, and drops control characters from the text but keeps its lines (5)', function (): void {
    expect(fn () => Message::of('a@example.test', 'S', "  \n "))->toThrow(InvalidMessage::class)
        ->and(Message::of('a@example.test', 'S', "One\x00\x1b two\r\nThree\tfour")->text)->toBe("One two\nThree\tfour\n");
});

it('refuses a stored message it did not write', function (array $stored): void {
    expect(fn () => Message::fromArray($stored))->toThrow(InvalidMessage::class);
})->with([
    'nothing' => [[]],
    'recipients that are not a list' => [['to' => 'a@example.test', 'subject' => 'S', 'text' => 'T', 'reply_to' => null]],
    'no subject' => [['to' => ['a@example.test'], 'text' => 'T', 'reply_to' => null]],
    'a reply-to that is not text' => [['to' => ['a@example.test'], 'subject' => 'S', 'text' => 'T', 'reply_to' => 5]],
]);

it('makes links on APP_URL only, and names the sender (11)', function (): void {
    $settings = new MailSettings('Waffle <noreply@waffle.example.com>', 'https://waffle.example.com/', 'Waffle');

    expect($settings->link('/invite/abc'))->toBe('https://waffle.example.com/invite/abc')
        ->and($settings->link('reset/xyz'))->toBe('https://waffle.example.com/reset/xyz')
        ->and($settings->appUrl)->toBe('https://waffle.example.com')
        ->and($settings->from)->toBe('Waffle <noreply@waffle.example.com>')
        ->and($settings->appName)->toBe('Waffle');
});

it('refuses a sender or an app address it cannot use (5, 11)', function (string $from, string $url): void {
    expect(fn () => new MailSettings($from, $url, 'App'))->toThrow(InvalidArgumentException::class);
})->with([
    'a sender that is not an address' => ['Waffle', 'https://app.example.test'],
    'a line break in the sender' => ["A <a@example.test>\r\nBcc: x@example.test", 'https://app.example.test'],
    'an app address with a path' => ['a@example.test', 'https://app.example.test/x'],
    'an app address that is not one' => ['a@example.test', 'app.example.test'],
]);

it('accepts a bare sender address too', function (): void {
    expect((new MailSettings('noreply@example.test', 'http://localhost:8080', 'App'))->link('/x'))->toBe('http://localhost:8080/x');
});

it('queues a message with a key that survives the round trip, so a retry is the same email (3)', function (): void {
    $job = SendMail::of(Message::of('a@example.test', 'S', 'T'));
    $again = SendMail::fromPayload($job->payload());

    expect(SendMail::type())->toBe('send-mail')
        ->and($job->key)->toMatch('/^[0-9a-f]{32}$/')
        ->and($again->key)->toBe($job->key)
        ->and($again->message)->toEqual($job->message)
        ->and(SendMail::of(Message::of('a@example.test', 'S', 'T'))->key)->not->toBe($job->key)
        ->and($job->merge(new RecordingJob(['z'])))->toBeInstanceOf(RecordingJob::class);
});

it('refuses a stored mail job it did not write', function (array $payload): void {
    expect(fn () => SendMail::fromPayload($payload))->toThrow(InvalidJobPayload::class);
})->with([
    'nothing' => [[]],
    'a key that is not one' => [['key' => 'x', 'message' => Message::of('a@example.test', 'S', 'T')->toArray()]],
    'a message that is not one' => [['key' => str_repeat('a', 32), 'message' => ['to' => []]]],
    'no message' => [['key' => str_repeat('a', 32)]],
]);

it('sends through the port with the job\'s key, and fails when the provider does, so the queue retries (3, 7)', function (): void {
    $mailer = new FakeMailer();
    $handler = new SendMailHandler($mailer);
    $job = SendMail::of(Message::of('a@example.test', 'S', 'T'));

    $handler->handle($job);
    $handler->handle($job);
    $mailer->down = true;

    expect($handler->jobClass())->toBe(SendMail::class)
        ->and($mailer->sent)->toHaveCount(2)
        ->and(array_column($mailer->sent, 'key'))->toBe([$job->key, $job->key])
        ->and(fn () => $handler->handle($job))->toThrow(RuntimeException::class);
});

it('without a key: writes the email to the log on a developer\'s machine, and refuses in production (8)', function (): void {
    $lines = [];
    $development = new NoMailer(false, static function (string $line) use (&$lines): void {
        $lines[] = $line;
    });
    $development->send(Message::of('a@example.test', 'Welcome', "Open https://app.example.test/invite/abc\n", 'r@example.test'), 'key1');

    $production = new NoMailer(true, static function (string $line): void {
        throw new LogicException('production never logs an email');
    });

    expect(implode("\n", $lines))->toContain('To: a@example.test')
        ->toContain('Reply-To: r@example.test')
        ->toContain('Subject: Welcome')
        ->toContain('https://app.example.test/invite/abc')
        ->and(fn () => $production->send(Message::of('a@example.test', 'S', 'T'), 'key2'))
        ->toThrow(RuntimeException::class, 'RESEND_API_KEY');
});
