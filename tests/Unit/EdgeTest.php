<?php

declare(strict_types=1);

/*
 * The server's front Caddy (deploy/edge/, docs/edge.md), asserted by shape:
 * it runs on the server only, beside every project, so the suite checks it
 * says what it must. The numbers cite docs/edge.md's failure list.
 */

function edgeFile(string $name): string
{
    $text = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/edge/' . $name);

    // Whole-line comments only, as in the projects' files; the lines go too.
    return (string) preg_replace('/^\s*#.*\n/m', '', $text);
}

/**
 * The text of the Caddyfile block that opens with `$header {`, braces counted.
 */
function edgeBlock(string $header): string
{
    $conf = edgeFile('Caddyfile');

    if (!preg_match('/^' . preg_quote($header, '/') . ' \{$/m', $conf, $match, PREG_OFFSET_CAPTURE)) {
        return '';
    }

    $start = $match[0][1];
    $depth = 0;

    for ($i = $start + strlen($match[0][0]) - 1, $length = strlen($conf); $i < $length; ++$i) {
        if ($conf[$i] === '{') {
            ++$depth;
        } elseif ($conf[$i] === '}' && --$depth === 0) {
            return substr($conf, $start, $i - $start + 1);
        }
    }

    return '';
}

it('owns 80 and 443 for the whole server, and the network the projects join (1, 3)', function (): void {
    $compose = edgeFile('docker-compose.yml');

    expect($compose)->toMatch('/^name: edge$/m')
        ->and($compose)->toContain("ports:\n      - \"80:80\"\n      - \"443:443\"\n      - \"443:443/udp\"")
        ->and($compose)->toMatch('/^networks:\n  edge:\n    name: edge$/m')
        ->and($compose)->toContain('restart: unless-stopped');
});

it('forwards the app\'s hostname to the app, and answers for no other (4)', function (): void {
    $sites = [];
    preg_match_all('/^([a-z0-9.-]+) \{$/m', edgeFile('Caddyfile'), $sites);

    // A site beside the app adds its own blocks (docs/edge.md, "A site beside the app").
    expect($sites[1])->toBe(['waffle.example.com'])
        ->and(edgeBlock('waffle.example.com'))->toContain('reverse_proxy waffle-app:80');
});

it('names the app by the hostname its own Compose file names', function (): void {
    preg_match('/SITE_ADDRESS: ([^\n]+)/', (string) file_get_contents(dirname(__DIR__, 2) . '/docker-compose.prod.yml'), $host);

    expect(edgeBlock(trim($host[1] ?? '')))->toContain('reverse_proxy waffle-app:80');
});

it('trusts no forwarded address from the internet, so a visitor cannot choose theirs (2)', function (): void {
    expect(edgeFile('Caddyfile'))->not->toContain('trusted_proxies')
        ->and(edgeFile('Caddyfile'))->not->toContain('header_up X-Forwarded');
});

it('retries for a few seconds while a project\'s Caddy restarts in a deploy (6)', function (): void {
    expect(edgeBlock('waffle.example.com'))->toContain('lb_try_duration 15s');
});

it('keeps the certificates in a named volume (7)', function (): void {
    $compose = edgeFile('docker-compose.yml');

    expect($compose)->toContain('- edge_data:/data')
        ->and($compose)->toMatch('/^volumes:\n  edge_data:\n  edge_config:$/m');
});

it('runs the same Caddy as the projects (8)', function (): void {
    preg_match('/image: (caddy:\S+)/', (string) file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml'), $project);

    expect(edgeFile('docker-compose.yml'))->toContain('image: ' . ($project[1] ?? 'missing'));
});

it('never reaches the cron port, and redirects plain HTTP to HTTPS (9, 10)', function (): void {
    expect(edgeFile('Caddyfile'))->not->toContain('8081')
        ->and(edgeFile('Caddyfile'))->not->toContain('auto_https')
        ->and(edgeFile('Caddyfile'))->not->toContain('http://');
});

it('names each project on the network by something no server is called, never the bare slug (11)', function (): void {
    preg_match_all('/reverse_proxy ([a-z0-9-]+):80/', edgeFile('Caddyfile'), $upstreams);

    expect($upstreams[1])->not->toBeEmpty();

    foreach ($upstreams[1] as $name) {
        expect($name)->toMatch('/-(app|site)$/');
    }
});
