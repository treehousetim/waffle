<?php

declare(strict_types=1);

/**
 * PHP-DI definitions for the platform every slice sits on.
 *
 * Every repository, service and middleware is listed explicitly — nothing in
 * this application is wired by convention. Configuration is read from the
 * environment here and injected; no class below src/config/ ever touches
 * $_ENV itself.
 *
 * Feature slices add their own definitions in src/config/container/<slice>.php
 * (merged at the bottom of this file).
 */

use App\Controllers\AuthController;
use App\Controllers\CronController;
use App\Controllers\HelloController;
use App\Domain\Auth\Permission;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Auth\DelightIdentity;
use App\Libraries\Clock\SystemClock;
use App\Libraries\Http\ErrorHandler;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Http\SpaShell;
use App\Libraries\Middleware\AuthMiddleware;
use App\Libraries\Middleware\CronMiddleware;
use App\Libraries\Middleware\PermissionMiddleware;
use App\Libraries\Middleware\SameSiteMiddleware;
use App\Models\HealthRepository;
use App\Models\TypeRepository;
use App\Models\UserRepository;
use Delight\Auth\Auth;
use Delight\Db\PdoDatabase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

use function DI\factory;

$str = static function (string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? null;

    if ($value === null || $value === '') {
        $fromShell = getenv($key);
        $value = $fromShell === false ? null : $fromShell;
    }

    return ($value === null || $value === '') ? $default : (string) $value;
};

$bool = static function (string $key, bool $default) use ($str): bool {
    $value = strtolower($str($key, $default ? 'true' : 'false'));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
};

$int = static function (string $key, int $default) use ($str): int {
    $value = $str($key, (string) $default);

    return is_numeric($value) ? (int) $value : $default;
};

$root = dirname(__DIR__, 2);

$definitions = [
    'config' => [
        'app' => [
            'env' => $str('APP_ENV', 'production'),
            'debug' => $bool('APP_DEBUG', false),
        ],
        'paths' => [
            'root' => $root,
            'public' => $root . '/src/public',
        ],
        'db' => [
            'host' => $str('DB_HOST', 'db'),
            'port' => $int('DB_PORT', 3306),
            'name' => $str('DB_NAME', 'waffle'),
            'user' => $str('DB_USER', 'waffle'),
            'password' => $str('DB_PASSWORD', 'waffle'),
        ],
        'cron' => [
            'secret' => $str('CRON_SECRET', ''),
        ],
        'auth' => [
            // Login throttling is on everywhere except the test suite, where it
            // would fail a run purely for hammering the same credentials.
            'throttling' => $str('APP_ENV', 'production') !== 'testing',
        ],
    ],

    // ---------------------------------------------------------------- storage

    PDO::class => factory(function (ContainerInterface $c): PDO {
        /** @var array{db:array{host:string,port:int,name:string,user:string,password:string}} $config */
        $config = $c->get('config');
        $db = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['name'],
        );

        return new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements. LIMIT values are therefore inlined with
            // an (int) cast in repositories, never bound.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }),

    // Delight\Auth wraps the *same* PDO instance, so it participates in the
    // transaction the test suite opens and rolls back around every test.
    PdoDatabase::class => factory(static fn (PDO $pdo): PdoDatabase => PdoDatabase::fromPdo($pdo)),

    Auth::class => factory(function (ContainerInterface $c): Auth {
        /** @var array{auth:array{throttling:bool}} $config */
        $config = $c->get('config');

        return new Auth(
            $c->get(PdoDatabase::class),
            null,
            null,
            $config['auth']['throttling'],
            /*
             * Resynchronise the session against the database on every request.
             *
             * Delight's default is five minutes, which means disabling an
             * account or changing a role takes up to five minutes to bite —
             * and somebody whose access was just revoked keeps working in the
             * tab they have open. Revocation has to be immediate. The cost is
             * one lookup by primary key per request.
             */
            0,
        );
    }),

    // ----------------------------------------------------------------- ports

    // Delight\Auth is final, so everything above Libraries/ depends on this
    // port instead. It is the seam that makes middleware and controllers
    // testable without a database session.
    IdentityPort::class => factory(static fn (Auth $auth): IdentityPort => new DelightIdentity($auth)),

    ClockPort::class => factory(static fn (): ClockPort => new SystemClock()),

    // ------------------------------------------------------------------- http

    ResponseFactoryInterface::class => factory(static fn (): ResponseFactoryInterface => new ResponseFactory()),

    JsonResponder::class => factory(
        static fn (ResponseFactoryInterface $responseFactory): JsonResponder => new JsonResponder($responseFactory)
    ),

    SpaShell::class => factory(function (ContainerInterface $c): SpaShell {
        /** @var array{paths:array{public:string}} $config */
        $config = $c->get('config');

        return new SpaShell($c->get(ResponseFactoryInterface::class), $config['paths']['public'] . '/index.html');
    }),

    // Whether to leak exception messages is decided by the error middleware
    // (from APP_DEBUG) and arrives as an argument, so it is not injected here.
    ErrorHandler::class => factory(
        static fn (JsonResponder $json, SpaShell $spa): ErrorHandler => new ErrorHandler($json, $spa)
    ),

    // ------------------------------------------------------------- middleware

    SameSiteMiddleware::class => factory(
        static fn (JsonResponder $json): SameSiteMiddleware => new SameSiteMiddleware($json)
    ),

    AuthMiddleware::class => factory(
        static fn (IdentityPort $identity, JsonResponder $json, UserRepository $users): AuthMiddleware
            => new AuthMiddleware($identity, $json, $users)
    ),

    CronMiddleware::class => factory(function (ContainerInterface $c): CronMiddleware {
        /** @var array{cron:array{secret:string}} $config */
        $config = $c->get('config');

        return new CronMiddleware($c->get(JsonResponder::class), $config['cron']['secret']);
    }),

    // `can:<permission>`, one PermissionMiddleware per permission, generated
    // below from the Permission enum so no route can name one that is not
    // there (docs/access-levels.md).

    // ----------------------------------------------------------- repositories

    // Memoised and shared: no other constructor builds its own TypeRepository.
    TypeRepository::class => factory(static fn (PDO $pdo): TypeRepository => new TypeRepository($pdo)),
    UserRepository::class => factory(static fn (PDO $pdo): UserRepository => new UserRepository($pdo)),
    HealthRepository::class => factory(static fn (PDO $pdo): HealthRepository => new HealthRepository($pdo)),

    // ------------------------------------------------------------ controllers

    AuthController::class => factory(
        static fn (JsonResponder $json, IdentityPort $identity): AuthController
            => new AuthController($json, $identity)
    ),
    HelloController::class => factory(
        static fn (JsonResponder $json, IdentityPort $identity): HelloController
            => new HelloController($json, $identity)
    ),
    CronController::class => factory(
        static fn (JsonResponder $json, HealthRepository $health): CronController
            => new CronController($json, $health)
    ),
];

// can:<permission> -- one guard per permission (docs/access-levels.md).
foreach (Permission::cases() as $permission) {
    $definitions['can:' . $permission->value] = factory(
        static fn (IdentityPort $identity, JsonResponder $json): PermissionMiddleware
            => new PermissionMiddleware($identity, $json, $permission)
    );
}

/*
 * Per-slice definitions.
 *
 * Each feature slice owns one file in src/config/container/ so that several
 * people (or agents) can add services at once without editing the same file.
 * Keys must stay unique across fragments — a collision is a duplicate service,
 * which is a bug, so it is rejected rather than silently won by whoever loads
 * last.
 */
foreach (glob(__DIR__ . '/container/*.php') ?: [] as $fragment) {
    /** @var array<string,mixed> $slice */
    $slice = require $fragment;

    $collisions = array_intersect_key($slice, $definitions);

    if ($collisions !== []) {
        throw new LogicException(sprintf(
            'Container fragment %s redefines: %s',
            basename($fragment),
            implode(', ', array_keys($collisions)),
        ));
    }

    $definitions += $slice;
}

return $definitions;
