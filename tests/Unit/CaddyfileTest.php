<?php

declare(strict_types=1);

/*
 * The web server's rules (docs/https.md), asserted by shape: a Caddyfile
 * cannot be exercised by the suite, so this checks it says what it must. The
 * numbers cite that document's failure list.
 */

function caddyfile(): string
{
    $conf = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/caddy/Caddyfile');

    // Whole-line comments only; nothing else in the file uses '#'.
    return (string) preg_replace('/^\s*#.*$/m', '', $conf);
}

/**
 * The text of the block that opens with `$header {`, braces counted.
 */
function caddyBlock(string $conf, string $header): string
{
    if (!preg_match('/^\s*' . preg_quote($header, '/') . '\s*\{/m', $conf, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $start = $match[0][1];
    // The block's own brace ends the match; a header can hold braces of its
    // own, as `{$SITE_ADDRESS}` does.
    $open = $start + strlen($match[0][0]) - 1;
    $depth = 0;

    for ($i = $open, $length = strlen($conf); $i < $length; ++$i) {
        if ($conf[$i] === '{') {
            ++$depth;
        } elseif ($conf[$i] === '}' && --$depth === 0) {
            return substr($conf, $start, $i - $start + 1);
        }
    }

    return '';
}

function composeFile(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $name);
}

/**
 * One service's section of a compose file: its line and everything indented
 * beneath it.
 */
function composeService(string $yaml, string $service): string
{
    if (!preg_match('/^  ' . preg_quote($service, '/') . ':\n((?:    .*\n|\s*\n)*)/m', $yaml, $match)) {
        return '';
    }

    return $match[1];
}

it('sends the headers an authenticated app needs, deferred so PHP cannot overwrite them (12)', function (): void {
    $headers = caddyBlock(caddyfile(), '(security_headers)');

    expect($headers)->toContain('defer')
        ->toContain('Strict-Transport-Security "max-age=31536000"')
        ->toContain('X-Content-Type-Options "nosniff"')
        ->toContain('Referrer-Policy "strict-origin-when-cross-origin"')
        ->toContain('-Server');
});

it('sets a content security policy that allows only the app\'s own code to run (12)', function (): void {
    preg_match('/Content-Security-Policy "([^"]+)"/', caddyBlock(caddyfile(), '(security_headers)'), $match);
    $directives = [];

    foreach (array_filter(array_map('trim', explode(';', $match[1] ?? ''))) as $directive) {
        $parts = preg_split('/\s+/', $directive);
        $directives[array_shift($parts)] = $parts;
    }

    expect($directives['default-src'] ?? null)->toBe(["'self'"])
        ->and($directives['object-src'] ?? null)->toBe(["'none'"])
        ->and($directives['base-uri'] ?? null)->toBe(["'self'"])
        ->and($directives['form-action'] ?? null)->toBe(["'self'"])
        // Nothing may frame the app: an overlay over a destructive button.
        ->and($directives['frame-ancestors'] ?? null)->toBe(["'none'"])
        // Scripts from the app only, never inline and never eval.
        ->and($directives['script-src'] ?? null)->toBe(["'self'"]);
});

it('puts the headers on every response of the site, error responses included (12)', function (): void {
    $site = caddyBlock(caddyfile(), '{$SITE_ADDRESS}');

    expect($site)->toContain('import security_headers')
        ->and(caddyBlock($site, 'handle_errors'))->toContain('import security_headers');
});

it('routes in a fixed order: cron refused, dotfiles refused, assets, then PHP (13, 14, 16)', function (): void {
    /*
     * One `route` block, because Caddy otherwise reorders directives by its own
     * rules, and the order is the security: a dotfile must be refused before
     * anything can serve it.
     */
    $route = caddyBlock(caddyBlock(caddyfile(), '{$SITE_ADDRESS}'), 'route');
    $order = [
        'respond /cron/* 404',
        'respond @dotfiles 404',
        'handle /assets/*',
        'Cache-Control "no-cache, no-store, must-revalidate"',
        'php_fastcgi php:9000',
        'file_server',
    ];
    // The last file_server: the first is inside the /assets/ handle.
    $positions = array_map(
        static fn (string $needle): int|false => $needle === 'file_server' ? strrpos($route, $needle) : strpos($route, $needle),
        $order,
    );

    expect($positions)->not->toContain(false);

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
});

it('refuses every path with a dotted segment (14)', function (): void {
    expect(caddyBlock(caddyfile(), '{$SITE_ADDRESS}'))->toMatch('#@dotfiles path_regexp /\\\\\.#');
});

it('caches only the hashed assets, and those forever (13)', function (): void {
    $conf = caddyfile();
    $assets = caddyBlock(caddyBlock($conf, '{$SITE_ADDRESS}'), 'handle /assets/*');

    expect($assets)->toContain('Cache-Control "public, max-age=31536000, immutable"')
        ->and(substr_count($conf, 'immutable'))->toBe(1);
});

it('caps request bodies at 2 MB (15)', function (): void {
    expect(caddyBlock(caddyBlock(caddyfile(), '{$SITE_ADDRESS}'), 'request_body'))->toContain('max_size 2MB');
});

it('serves /cron on its own port to this machine and its containers only (16)', function (): void {
    $cron = caddyBlock(caddyfile(), 'http://:8081');

    preg_match('/@outside not remote_ip ([^\n]+)/', $cron, $match);

    expect($cron)->not->toBe('')
        ->and(preg_split('/\s+/', trim($match[1] ?? '')))->toBe(['127.0.0.0/8', '172.16.0.0/12', '::1/128'])
        ->and($cron)->toContain('respond @outside 403')
        ->and(caddyBlock($cron, 'handle /cron/*'))->toContain('php_fastcgi php:9000')
        // Nothing else lives on the cron port.
        ->and($cron)->toMatch('/respond 404\s*\}\s*\}\s*$/');
});

it('serves plain HTTP locally and never asks for a certificate (10)', function (): void {
    $caddy = composeService(composeFile('docker-compose.yml'), 'caddy');

    expect($caddy)->toContain('image: caddy:2.11')
        ->toContain('SITE_ADDRESS: ":80"')
        ->toContain('./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro')
        ->toContain('"${HTTP_PORT:-8080}:80"');
});

it('names the production hostname once, and the workflow agrees with it (11)', function (): void {
    preg_match_all('/SITE_ADDRESS: ([^\n]+)/', composeFile('docker-compose.prod.yml'), $addresses);
    preg_match('#url: https://([^\s/]+)#', composeFile('.github/workflows/deploy.yml'), $url);

    expect($addresses[1])->toHaveCount(1)
        // A bare hostname: deploy.sh reads this line to check DNS.
        ->and($addresses[1][0])->toMatch('/^[a-z0-9.-]+\.[a-z]+$/')
        ->and($url[1] ?? null)->toBe($addresses[1][0]);
});

it('publishes 80 and 443 to the world and the cron port to loopback only (16)', function (): void {
    preg_match_all('/^\s+- "([^"]+)"$/m', composeService(composeFile('docker-compose.prod.yml'), 'caddy'), $ports);

    expect(array_values(array_filter($ports[1], static fn (string $p): bool => !str_contains($p, '/data') && !str_contains($p, '/config') && !str_contains($p, 'Caddyfile') && !str_contains($p, '/var/www'))))
        ->toBe(['80:80', '443:443', '443:443/udp', '127.0.0.1:8081:8081']);
});

it('keeps the certificate store in a named volume that a deploy cannot wipe (7)', function (string $file): void {
    $compose = composeFile($file);

    expect(composeService($compose, 'caddy'))->toContain('- caddy_data:/data')
        ->and($compose)->toMatch('/^volumes:\n(?:  .*\n)*  caddy_data:/m');
})->with(['docker-compose.yml', 'docker-compose.prod.yml']);

it('leaves nothing of nginx behind', function (): void {
    expect(is_dir(dirname(__DIR__, 2) . '/docker/nginx'))->toBeFalse()
        ->and(composeFile('docker-compose.yml'))->not->toContain('nginx')
        ->and(composeFile('docker-compose.prod.yml'))->not->toContain('nginx');
});

it('names the Compose project for the slug, whatever folder the project is in', function (): void {
    // Containers and volumes then belong to the project wherever it is
    // cloned; on the server the folder is /opt/<slug>, so the names agree.
    expect(composeFile('docker-compose.yml'))->toMatch('/^name: waffle$/m');
});
