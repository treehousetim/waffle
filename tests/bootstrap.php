<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Three jobs, in order:
 *   1. Load .env.testing — and ONLY .env.testing — then refuse to go further
 *      unless it describes a testing environment against a *_test database.
 *   2. Start the session before anything constructs Delight\Auth, so Auth
 *      attaches to an existing session instead of configuring one and trying to
 *      send a cookie from a CLI process.
 *   3. Run the real migrations once per run, so the schema under test is always
 *      the schema the migrations produce.
 */

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Delight\Auth's constructor calls header_remove() and header(). Under CLI
// those warn once anything has been printed, and the suite treats warnings as
// failures — so the whole run happens inside an output buffer, which keeps
// headers_sent() false. PHP flushes it at shutdown.
ob_start();

$root = dirname(__DIR__);

$fail = static function (string $message): never {
    fwrite(STDERR, "\n  tests/bootstrap.php: {$message}\n\n");
    exit(1);
};

if (!is_file($root . '/.env.testing')) {
    $fail('.env.testing is missing. It is committed to the repository — restore it.');
}

// Array-backed: nothing here touches the ambient environment, so an exported
// DB_NAME or APP_ENV can never redirect the suite at another database.
$values = Dotenv::createArrayBacked($root, '.env.testing')->load();

$appEnv = (string) ($values['APP_ENV'] ?? '');
$dbName = (string) ($values['DB_NAME'] ?? '');

if ($appEnv !== 'testing') {
    $fail("refusing to run: .env.testing sets APP_ENV='{$appEnv}', expected 'testing'.");
}

if (!str_ends_with($dbName, '_test')) {
    $fail("refusing to run: .env.testing sets DB_NAME='{$dbName}', which does not end in _test.");
}

foreach ($values as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

// Delight\Auth only configures the session when there is not one already. By
// starting it here with cookies off, its ini_set() calls never run and the
// suite never tries to emit a Set-Cookie header from PHP CLI.
ini_set('session.use_cookies', '0');
ini_set('session.use_trans_sid', '0');
ini_set('session.cache_limiter', '');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// PHP 8.5 raises a deprecation when it *compiles* a class that declares an
// implicitly-nullable parameter, and Delight\Auth still does. Compiling those
// classes here means the deprecation is raised once, before PHPUnit installs
// its error handler, rather than being blamed on whichever test happened to
// autoload them first.
foreach ([
    Delight\Auth\Auth::class,
    Delight\Auth\Administration::class,
    Delight\Db\PdoDatabase::class,
] as $vendorClass) {
    class_exists($vendorClass);
}

// The schema under test is the real migrations, applied once per run.
$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($root . '/vendor/bin/phinx')
    . ' migrate -e testing'
    . ' -c ' . escapeshellarg($root . '/phinx.php')
    . ' 2>&1';

exec($command, $output, $exitCode);

if ($exitCode !== 0) {
    $fail("phinx migrate -e testing failed:\n" . implode("\n", $output));
}
