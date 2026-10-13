<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * A `zip` or `file` source: HTTPS only, at most five redirects and each of
 * them HTTPS, at most 10 MB (9).
 */
final class Download
{
    public const LIMIT = 10 * 1024 * 1024;
    public const REDIRECTS = 5;

    public static function fetch(Transport $transport, string $url, string $dest, int $limit = self::LIMIT): void
    {
        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            if (!preg_match('#\Ahttps://[^/\s]+#', $url, $origin)) {
                throw new Refusal('only HTTPS downloads are allowed, not ' . Terminal::safe($url) . ' (9).');
            }

            $response = $transport->get($url, $limit + 1);

            if ($response['status'] >= 300 && $response['status'] < 400) {
                $location = (string) $response['location'];
                $url = str_starts_with($location, '/') && !str_starts_with($location, '//') ? $origin[0] . $location : $location;
                continue;
            }

            if ($response['status'] !== 200) {
                throw new Refusal(sprintf('the download answered %d, not 200 (9).', $response['status']));
            }

            if (strlen($response['body']) > $limit) {
                throw new Refusal(sprintf('the download is larger than %d MB (9).', intdiv($limit, 1024 * 1024)));
            }

            file_put_contents($dest, $response['body']);

            return;
        }

        throw new Refusal(sprintf('the download redirected more than %d times (9).', self::REDIRECTS));
    }
}
