<?php

declare(strict_types=1);

use App\Domain\Mail\InboundMail;
use App\Domain\Ports\QueuePort;
use App\Libraries\Forms\Sites;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\Svix;
use Tests\TestCase;

/* Resend's webhook through the router (docs/mail.md, 13., 15.). */

/** The test container's clock is frozen; a delivery is signed at its time. */
function webhookNow(): int
{
    return TestCase::container()->get(App\Domain\Ports\ClockPort::class)->now()->getTimestamp();
}

function webhook(string $body, ?string $signature, string $id = 'msg_route', ?int $at = null): ResponseInterface
{
    $at ??= webhookNow();
    $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/webhooks/resend')
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('svix-id', $id)
        ->withHeader('svix-timestamp', (string) $at)
        ->withBody((new StreamFactory())->createStream($body));

    if ($signature !== null) {
        $request = $request->withHeader('svix-signature', $signature);
    }

    return TestCase::app()->handle($request);
}

it('accepts a delivery Resend signed, and does nothing again for a repeat (13, 14)', function (): void {
    $body = '{"type":"email.received","data":{"email_id":"em_route"}}';
    $queue = TestCase::container()->get(QueuePort::class);
    $since = count($queue->enqueued);
    $at = webhookNow();

    $first = webhook($body, Svix::sign(Svix::SECRET, 'msg_route', $at, $body), 'msg_route', $at);
    $again = webhook($body, Svix::sign(Svix::SECRET, 'msg_route', $at, $body), 'msg_route', $at);
    $queued = array_filter(array_slice($queue->enqueued, $since), static fn (array $q): bool => $q['job'] instanceof InboundMail);

    expect($first->getStatusCode())->toBe(200)
        ->and($again->getStatusCode())->toBe(200)
        ->and($queued)->toHaveCount(1);
});

it('answers 401 to a delivery it cannot trust (13)', function (): void {
    expect(webhook('{}', null)->getStatusCode())->toBe(401)
        ->and(webhook('{}', 'v1,AAAA')->getStatusCode())->toBe(401);
});

it('refuses a site posting the Email form as if it were its own (15)', function (): void {
    $sites = TestCase::container()->get(Sites::class);
    [, $key] = $sites->issueKey($sites->create('inbound-site', 'Inbound site', 'https://inbound.example')->id);

    $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/site/forms/email', ['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeader('X-Site-Key', $key)
        ->withHeader('Content-Type', 'application/json')
        ->withBody((new StreamFactory())->createStream('{"email":"a@example.test","message":"x"}'));

    expect(TestCase::app()->handle($request)->getStatusCode())->toBe(404);
});
