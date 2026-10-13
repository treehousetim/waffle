<?php

declare(strict_types=1);

namespace ModuleTool\Clients;

use ModuleTool\Refusal;
use ModuleTool\Transport;

/**
 * The network, and nothing else: outside coverage for the reason AGENTS.md
 * accepts. Download holds every decision about what it returns.
 */
final class HttpsTransport implements Transport
{
    public function get(string $url, int $maxBytes): array
    {
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30]]);
        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new Refusal("could not fetch {$url}.");
        }

        $body = (string) stream_get_contents($stream, $maxBytes);
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        fclose($stream);
        $status = 0;
        $location = null;

        foreach ($headers as $header) {
            if (preg_match('#\AHTTP/\S+ (\d{3})#', $header, $match)) {
                $status = (int) $match[1];
            } elseif (preg_match('/\ALocation:\s*(.+)\z/i', trim($header), $match)) {
                $location = $match[1];
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => $body];
    }
}
