<?php

declare(strict_types=1);

namespace App\Libraries\Clients;

use App\Domain\Mail\Message;
use App\Domain\Ports\MailPort;
use GuzzleHttp\Client;

/**
 * MailPort over Resend's HTTP API (docs/mail.md). The key is a Resend API key
 * with sending access only. `Idempotency-Key` is the email's own key, which
 * Resend remembers for a day, so a job run twice sends once (3.). Guzzle
 * throws on any answer that is not a success, so the queue retries (7.).
 *
 * Network only, so excluded from coverage: keep it thin.
 */
final readonly class ResendMailer implements MailPort
{
    public function __construct(
        private Client $http,
        private string $apiKey,
        private string $from,
    ) {
    }

    public function send(Message $message, string $key): void
    {
        $body = ['from' => $this->from, 'to' => $message->to, 'subject' => $message->subject, 'text' => $message->text];

        if ($message->replyTo !== null) {
            $body['reply_to'] = $message->replyTo;
        }

        $this->http->post('https://api.resend.com/emails', [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Idempotency-Key' => $key],
            'json' => $body,
            'timeout' => 20,
        ]);
    }
}
