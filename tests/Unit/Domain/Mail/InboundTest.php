<?php

declare(strict_types=1);

use App\Domain\Mail\InboundEmail;
use App\Domain\Mail\InboundMail;
use App\Domain\Mail\InvalidSignature;
use App\Domain\Mail\SvixSignature;
use App\Domain\Queue\InvalidJobPayload;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\Support\Svix;

/* Mail sent to the domain (docs/mail.md, "Mail sent to the domain", 13.–17.). */

it('accepts a delivery Resend signed, among several signatures, within five minutes (13)', function (): void {
    $now = 1_800_000_000;
    $body = '{"type":"email.received","data":{"email_id":"abc"}}';
    $header = 'v1,bm90LXRoaXMtb25l ' . Svix::sign(Svix::SECRET, 'msg_1', $now - 200, $body);

    SvixSignature::verify(Svix::SECRET, 'msg_1', (string) ($now - 200), $header, $body, $now);

    expect(true)->toBeTrue();
});

it('refuses a delivery that is unsigned, signed otherwise, tampered with, too old or too new, or with no secret (13)', function (string $secret, string $id, string $timestamp, string $header, string $body): void {
    expect(fn () => SvixSignature::verify($secret, $id, $timestamp, $header, $body, 1_800_000_000))->toThrow(InvalidSignature::class);
})->with([
    'no secret' => ['', 'msg_1', '1800000000', Svix::sign(Svix::SECRET, 'msg_1', 1_800_000_000, '{}'), '{}'],
    'another secret' => ['whsec_b3RoZXItc2VjcmV0', 'msg_1', '1800000000', Svix::sign(Svix::SECRET, 'msg_1', 1_800_000_000, '{}'), '{}'],
    'the body changed' => [Svix::SECRET, 'msg_1', '1800000000', Svix::sign(Svix::SECRET, 'msg_1', 1_800_000_000, '{}'), '{"x":1}'],
    'another id' => [Svix::SECRET, 'msg_2', '1800000000', Svix::sign(Svix::SECRET, 'msg_1', 1_800_000_000, '{}'), '{}'],
    'too old' => [Svix::SECRET, 'msg_1', '1799999600', Svix::sign(Svix::SECRET, 'msg_1', 1_799_999_600, '{}'), '{}'],
    'too new' => [Svix::SECRET, 'msg_1', '1800000400', Svix::sign(Svix::SECRET, 'msg_1', 1_800_000_400, '{}'), '{}'],
    'not a timestamp' => [Svix::SECRET, 'msg_1', 'soon', Svix::sign(Svix::SECRET, 'msg_1', 1_800_000_000, '{}'), '{}'],
    'no signature' => [Svix::SECRET, 'msg_1', '1800000000', '', '{}'],
    'another version' => [Svix::SECRET, 'msg_1', '1800000000', 'v2,' . substr(Svix::sign(Svix::SECRET, 'msg_1', 1_800_000_000, '{}'), 3), '{}'],
    'a secret that is not base64' => ['whsec_!!!', 'msg_1', '1800000000', 'v1,x', '{}'],
]);

it('reads the message Resend received as the Inbox\'s fields, its sender\'s name from the From header (16)', function (): void {
    $fields = InboundEmail::fields([
        'from' => 'Ada@Example.test',
        'to' => ['hello@waffle.example.com'],
        'subject' => 'A question',
        'text' => "Hello,\r\nis there parking?",
        'html' => '<p>ignored</p>',
        'headers' => ['from' => 'Ada Lovelace <ada@example.test>'],
    ]);

    expect($fields)->toBe([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'to' => 'hello@waffle.example.com',
        'subject' => 'A question',
        'message' => "Hello,\nis there parking?",
    ]);
});

it('turns a message with only HTML into its text, and one with neither into a line saying so (16)', function (): void {
    $html = InboundEmail::fields(['from' => 'b@example.test', 'to' => [], 'subject' => '', 'text' => null, 'html' => '<div>One &amp; two<br>three</div><p>four</p><script>x()</script>']);
    $empty = InboundEmail::fields(['from' => 'b@example.test', 'to' => ['hello@waffle.example.com'], 'subject' => null, 'text' => '   ', 'html' => null]);

    expect($html['message'])->toBe("One & two\nthree\nfour")
        ->and($html['subject'])->toBe('(no subject)')
        ->and($html['name'])->toBe('')
        ->and($html['to'])->toBe('')
        ->and($empty['message'])->toBe('(no text)');
});

it('refuses a message with no sender address it can use', function (): void {
    expect(fn () => InboundEmail::fields(['from' => 'nobody', 'to' => []]))->toThrow(InvalidArgumentException::class);
});

it('queues only the received email\'s id', function (): void {
    expect(InboundMail::type())->toBe('inbound-mail')
        ->and(InboundMail::of('4ef9a417-02e9')->payload())->toBe(['email_id' => '4ef9a417-02e9'])
        ->and(InboundMail::fromPayload(['email_id' => 'abc'])->emailId)->toBe('abc')
        ->and(InboundMail::of('a')->merge(new RecordingJob(['z'])))->toBeInstanceOf(RecordingJob::class)
        ->and(fn () => InboundMail::fromPayload(['email_id' => '']))->toThrow(InvalidJobPayload::class)
        ->and(fn () => InboundMail::fromPayload(['email_id' => '../x']))->toThrow(InvalidJobPayload::class);
});
