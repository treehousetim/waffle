<?php

declare(strict_types=1);

/*
 * tools/composer-age.php — is anything in composer.lock younger than the
 * cooldown? (docs/supply-chain.md, the Composer half.)
 *
 *   php tools/composer-age.php --days 7            # list young packages; exit 1 if any
 *   php tools/composer-age.php --days 7 --record   # append them to cooldown-exceptions.txt
 *
 * Run by tools/composer.sh inside the php container after anything that picks
 * versions, and loaded by tests/Unit/ComposerTest.php, which applies the same
 * rule to the real lock on every run. Outside src/app: it is tooling, not the
 * application. The numbers cite the Composer failure list.
 */

/**
 * Packages released within $days of $now, unless recorded as an exception for
 * that exact version. A package with no release time cannot be shown to be
 * old, so it counts as young (3).
 *
 * @param array<string,mixed>  $lock       decoded composer.lock
 * @param array<string,string> $exceptions package name => exact version
 *
 * @return list<string> one line per package, "name version (why)"
 */
function composerYoungPackages(array $lock, array $exceptions, int $days, DateTimeImmutable $now): array
{
    $young = [];
    $cutoff = $now->modify('-' . $days . ' days');

    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            $name = (string) ($package['name'] ?? '');
            $version = (string) ($package['version'] ?? '');

            if (($exceptions[$name] ?? null) === $version) {
                continue;
            }

            $time = $package['time'] ?? null;

            if (!is_string($time) || $time === '') {
                $young[] = "{$name} {$version} (no release time recorded)";

                continue;
            }

            $released = new DateTimeImmutable($time);

            if ($released > $cutoff) {
                $ago = max(0, (int) floor(($now->getTimestamp() - $released->getTimestamp()) / 86400));
                $young[] = sprintf('%s %s (released %d %s ago)', $name, $version, $ago, $ago === 1 ? 'day' : 'days');
            }
        }
    }

    return $young;
}

/**
 * The recorded exceptions (4): one per line, "vendor/package version", then
 * anything (a date, a reason). Blank lines and # comments are ignored.
 *
 * @return array<string,string> package name => exact version
 */
function composerCooldownExceptions(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $exceptions = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = preg_split('/\s+/', $line);

        if (is_array($parts) && count($parts) >= 2) {
            $exceptions[$parts[0]] = $parts[1];
        }
    }

    return $exceptions;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__);
    $days = 7;
    $record = false;

    for ($i = 1; $i < $argc; ++$i) {
        if ($argv[$i] === '--days' && isset($argv[$i + 1]) && ctype_digit($argv[$i + 1])) {
            $days = (int) $argv[++$i];
        } elseif ($argv[$i] === '--record') {
            $record = true;
        } else {
            fwrite(STDERR, "usage: php tools/composer-age.php [--days N] [--record]\n");
            exit(2);
        }
    }

    $lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);

    if (!is_array($lock)) {
        fwrite(STDERR, "composer.lock is missing or not JSON.\n");
        exit(2);
    }

    $exceptionsFile = $root . '/cooldown-exceptions.txt';
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $young = composerYoungPackages($lock, composerCooldownExceptions($exceptionsFile), $days, $now);

    if ($young === []) {
        exit(0);
    }

    if ($record) {
        $lines = '';

        foreach ($young as $entry) {
            [$name, $version] = explode(' ', $entry, 3);
            $lines .= sprintf("%s %s  %s cooldown skipped (COMPOSER_COOLDOWN_DAYS=0)\n", $name, $version, $now->format('Y-m-d'));
        }

        file_put_contents($exceptionsFile, $lines, FILE_APPEND);
        fwrite(STDOUT, implode("\n", $young) . "\n");
        exit(0);
    }

    fwrite(STDOUT, implode("\n", $young) . "\n");
    exit(1);
}
