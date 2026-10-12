<?php

declare(strict_types=1);

/*
 * The security headers, and the nginx rule that kept throwing them away.
 *
 * `add_header` does not merge across levels: a location that sets any header of
 * its own inherits none from the server block. Three locations here set their
 * own `Cache-Control` -- /assets/, the SPA shell and the PHP handler -- and the
 * PHP handler is the one that serves the app shell, so without the include the
 * main document would arrive with no HSTS while the config appeared to set it
 * for everything.
 *
 * A config file cannot be exercised by the suite, so this asserts the shape
 * instead: every location that sets a header includes the shared file. It is
 * the only thing that will still be true after somebody adds a caching rule in
 * a hurry.
 */

function nginxConf(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/docker/nginx/' . $name);
}

/**
 * Every `location { ... }` block in a config, as text.
 *
 * Brace-counted rather than regexed: nginx nests, and a naive match on the
 * next `}` would cut a block short and quietly pass.
 *
 * @return list<string>
 */
function nginxLocations(string $conf): array
{
    $blocks = [];
    $offset = 0;

    while (($start = strpos($conf, 'location ', $offset)) !== false) {
        $open = strpos($conf, '{', $start);

        if ($open === false) {
            break;
        }

        $depth = 0;
        $end = $open;

        for ($i = $open, $length = strlen($conf); $i < $length; ++$i) {
            if ($conf[$i] === '{') {
                ++$depth;
            } elseif ($conf[$i] === '}') {
                --$depth;

                if ($depth === 0) {
                    $end = $i;

                    break;
                }
            }
        }

        $blocks[] = substr($conf, $start, $end - $start + 1);
        $offset = $end + 1;
    }

    return $blocks;
}

it('sets the headers an authenticated app needs', function (): void {
    $headers = nginxConf('security-headers.conf');

    expect($headers)->toContain('Strict-Transport-Security')
        // An app with destructive buttons must not be framable: a transparent
        // overlay over one of them is the whole attack.
        ->and($headers)->toContain("frame-ancestors 'none'")
        ->and($headers)->toContain('nosniff')
        ->and($headers)->toContain('Referrer-Policy');

    // And every one of them `always`, or none is sent on an error response --
    // which is exactly when a browser is most willing to render something.
    $directives = array_values(array_filter(
        array_map('trim', explode("\n", $headers)),
        static fn (string $line): bool => str_starts_with($line, 'add_header'),
    ));

    expect($directives)->toHaveCount(4)
        ->and($directives)->each->toEndWith('always;');
});

it('sets a content security policy that allows only the app\'s own code to run', function (): void {
    preg_match('/Content-Security-Policy "([^"]+)"/', nginxConf('security-headers.conf'), $match);
    $directives = [];

    foreach (array_filter(array_map('trim', explode(';', $match[1] ?? ''))) as $directive) {
        $parts = preg_split('/\s+/', $directive);
        $directives[array_shift($parts)] = $parts;
    }

    expect($directives['default-src'] ?? null)->toBe(["'self'"])
        ->and($directives['object-src'] ?? null)->toBe(["'none'"])
        ->and($directives['base-uri'] ?? null)->toBe(["'self'"])
        ->and($directives['form-action'] ?? null)->toBe(["'self'"])
        ->and($directives['frame-ancestors'] ?? null)->toBe(["'none'"])
        // Scripts from the app only, never inline and never eval.
        ->and($directives['script-src'] ?? null)->toBe(["'self'"]);
});

it('serves /cron on port 80 to this machine and its containers only', function (): void {
    $conf = nginxConf('production.conf');
    $allowed = [];

    foreach (nginxLocations($conf) as $block) {
        if (preg_match('#^location \^~ /cron/#', trim($block)) && str_contains($block, 'allow')) {
            preg_match_all('/allow\s+([^;]+);/', $block, $matches);
            $allowed[] = $matches[1];
        }
    }

    expect($allowed)->toBe([['127.0.0.0/8', '172.16.0.0/12', '::1/128']]);
});

it('includes them in every location that sets a header of its own', function (string $file): void {
    $conf = nginxConf($file);
    $include = 'security-headers.conf';

    $offenders = [];

    foreach (nginxLocations($conf) as $block) {
        if (!str_contains($block, 'add_header')) {
            continue;
        }

        if (!str_contains($block, $include)) {
            $line = strtok($block, "\n");
            $offenders[] = is_string($line) ? trim($line) : 'unknown location';
        }
    }

    expect($offenders)->toBe([])
        // And the server level, for every location that sets nothing.
        ->and($conf)->toContain('include /etc/nginx/conf.d/' . $include);
})->with(['production.conf', 'default.conf']);

it('mounts the include, because nginx will not start without it', function (string $file): void {
    /*
     * The failure mode to want. A missing include is a refusal to start rather
     * than a server that comes up with no security headers and says nothing
     * about it.
     */
    $compose = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

    expect($compose)->toContain('security-headers.conf:/etc/nginx/conf.d/security-headers.conf:ro');
})->with(['docker-compose.prod.yml', 'docker-compose.yml']);

it('over HTTPS, answers /cron with 404 and caps request bodies at 2 MB', function (): void {
    $conf = nginxConf('production.conf');
    $https = substr($conf, (int) strpos($conf, 'listen 443'));

    expect($https)->toContain("location ^~ /cron/ {\n        return 404;")
        ->toContain('client_max_body_size 2m;');
});
