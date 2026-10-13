<?php

declare(strict_types=1);

namespace Tests\Support;

use ModuleTool\Transport;
use RuntimeException;

/**
 * The module command's network, canned: each URL answers what it is given,
 * and any other URL throws, so a test that reaches the network fails loudly.
 */
final class FakeTransport implements Transport
{
    /** @var list<string> */
    public array $requests = [];

    /** @param array<string,array{0:int,1:?string,2:string}> $responses url => [status, location, body] */
    public function __construct(private readonly array $responses)
    {
    }

    public function get(string $url, int $maxBytes): array
    {
        $this->requests[] = $url;

        if (!isset($this->responses[$url])) {
            throw new RuntimeException("FakeTransport: nothing canned for {$url}");
        }

        [$status, $location, $body] = $this->responses[$url];

        return ['status' => $status, 'location' => $location, 'body' => substr($body, 0, $maxBytes)];
    }
}
