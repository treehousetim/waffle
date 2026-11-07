<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use InvalidArgumentException;

/**
 * Who the app's email is from, and where its links lead (docs/mail.md):
 * `MAIL_FROM` and `APP_URL`, checked once so every email can trust them (5.,
 * 11.).
 */
final readonly class MailSettings
{
    public string $appUrl;

    public function __construct(public string $from, string $appUrl, public string $appName)
    {
        $address = preg_match('/<([^<>]+)>\s*$/', $from, $match) === 1 ? $match[1] : $from;

        if (preg_match('/[\x00-\x1F\x7F]/', $from) === 1 || filter_var(trim($address), FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('MAIL_FROM must be an address, or a name and an <address>.');
        }

        $url = parse_url($appUrl);

        if ($url === false || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || ($url['host'] ?? '') === ''
            || !in_array($url['path'] ?? '', ['', '/'], true) || isset($url['query']) || isset($url['fragment'])) {
            throw new InvalidArgumentException('APP_URL must be the app\'s address, like https://app.example.com, with no path.');
        }

        $this->appUrl = rtrim($appUrl, '/');
    }

    /** An address in the app, for an email's link (11.). */
    public function link(string $path): string
    {
        return $this->appUrl . '/' . ltrim($path, '/');
    }
}
