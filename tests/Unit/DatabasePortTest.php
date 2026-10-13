<?php

declare(strict_types=1);

/*
 * Where MariaDB is reachable from. Nothing in the app needs a published port:
 * PHP reaches `db:3306` on the Compose network, and the tests and the nightly
 * backup run inside containers with `docker compose exec`. Locally it is
 * published for a desktop database client, on loopback and an uncommon port;
 * in production it is not published at all.
 */

function dbServiceIn(string $file): string
{
    $yaml = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

    return preg_match('/^  db:\n((?:    .*\n|\s*\n)*)/m', $yaml, $match) ? $match[1] : '';
}

it('publishes the database locally on loopback only, at 11020 unless overridden', function (): void {
    preg_match_all('/^\s+- "([^"]+)"$/m', dbServiceIn('docker-compose.yml'), $ports);

    expect($ports[1])->toBe(['127.0.0.1:${DB_HOST_PORT:-11020}:3306']);
});

it('publishes the database nowhere in production', function (): void {
    $db = dbServiceIn('docker-compose.prod.yml');

    // !reset clears the list inherited from the base file; without it Compose
    // would merge in the local port.
    expect($db)->toContain('ports: !reset []')
        ->and($db)->not->toMatch('/^\s+- "[^"]*3306"$/m');
});
