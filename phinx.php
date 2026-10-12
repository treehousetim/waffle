<?php

declare(strict_types=1);

/**
 * Phinx configuration.
 *
 * Environments:
 *   development  reads the container environment (docker-compose.yml's defaults)
 *   testing      reads .env.testing ONLY, and refuses anything but a *_test database
 *   production   reads the injected environment (doppler run -- ...)
 *
 * The testing environment is deliberately array-backed: it never inherits an
 * ambient DB_NAME, so a stray environment variable can never point the suite at
 * the development or production database.
 */

require_once __DIR__ . '/vendor/autoload.php';

$root = __DIR__;

/** @var array<string,string> $testingEnv */
$testingEnv = is_file($root . '/.env.testing')
    ? Dotenv\Dotenv::createArrayBacked($root, '.env.testing')->safeLoad()
    : [];

$read = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? null;

    if ($value === null || $value === '') {
        $fromShell = getenv($key);
        $value = $fromShell === false ? null : $fromShell;
    }

    return ($value === null || $value === '') ? $default : (string) $value;
};

$readTesting = static function (string $key, string $default = '') use ($testingEnv): string {
    $value = $testingEnv[$key] ?? '';

    return $value === '' ? $default : (string) $value;
};

$connection = static fn (callable $get): array => [
    'adapter' => 'mysql',
    'host' => $get('DB_HOST', 'db'),
    'port' => (int) $get('DB_PORT', '3306'),
    'name' => $get('DB_NAME', 'waffle'),
    'user' => $get('DB_USER', 'waffle'),
    'pass' => $get('DB_PASSWORD', 'waffle'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

$testing = $connection($readTesting);

/*
 * Hard guard: the testing environment may only ever address a *_test database.
 *
 * Only enforced when .env.testing is actually present. Production does not ship
 * test fixtures, and without this condition `phinx migrate -e production` on the
 * server would exit 1 — the fallbacks in $readTesting would yield the *dev*
 * database name, which correctly fails the _test check, for a config nobody
 * asked for. When the file is absent the testing environment is left pointing at
 * nothing, so selecting it fails loudly instead of silently connecting somewhere.
 */
if (is_file($root . '/.env.testing')) {
    if (!str_ends_with($testing['name'], '_test')) {
        fwrite(STDERR, "phinx.php: refusing to define a testing environment for database '{$testing['name']}' — the name must end in _test.\n");
        exit(1);
    }
} else {
    $testing['name'] = '';
}

return [
    'paths' => [
        'migrations' => $root . '/src/app/phinx/migrations',
        'seeds' => $root . '/src/app/phinx/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'development' => $connection($read),
        'testing' => $testing,
        'production' => $connection($read),
    ],
    'version_order' => 'creation',
];
