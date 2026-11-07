<?php

declare(strict_types=1);

namespace App\Libraries\Clients;

use App\Domain\Ports\ReceivedMailPort;
use GuzzleHttp\Client;
use RuntimeException;

/**
 * ReceivedMailPort over Resend's received-email API (docs/mail.md):
 * `GET /emails/receiving/{id}`, with a key that can read it. Guzzle throws on
 * any answer that is not a success, so the queue retries (17.).
 *
 * Network only, so excluded from coverage: keep it thin.
 */
final readonly class ResendReceiving implements ReceivedMailPort
{
    public function __construct(private Client $http, private string $apiKey)
    {
    }

    public function fetch(string $emailId): array
    {
        $response = $this->http->get('https://api.resend.com/emails/receiving/' . rawurlencode($emailId), [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'],
            'timeout' => 20,
        ]);
        $email = json_decode((string) $response->getBody(), true);

        if (!is_array($email)) {
            throw new RuntimeException("Resend's answer for {$emailId} is not an email.");
        }

        return $email;
    }
}
