<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Auth\Role;
use Delight\Auth\Auth;
use PDO;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\Factory;

/**
 * Base class for Feature and Unit tests.
 *
 * The container and the Slim application are built once for the process,
 * through exactly the same src/config/bootstrap.php that src/public/index.php
 * uses. What is swapped in is the PDO instance — the same one the tests open a
 * transaction on, so application code runs inside that transaction and every
 * write is rolled back after the test — and every port, bound to a fake by the
 * fragments in tests/Support/Overrides/.
 */
abstract class TestCase extends PHPUnitTestCase
{
    private static ?App $app = null;

    private static ?PDO $pdo = null;

    private static int $transactionDepth = 0;

    /**
     * Headers merged into every request this test makes (see withCronSecret()).
     *
     * @var array<string,string>
     */
    private array $defaultHeaders = [];

    /** What REMOTE_ADDR reads as. Overridden per test with from(). */
    protected string $clientAddress = '127.0.0.1';

    /**
     * Container entries this test replaced, and what they were before.
     *
     * @var array<string,mixed>
     */
    private array $swapped = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A previous test may have called logOut(), which destroys the session.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [];
        $_COOKIE = [];

        $this->defaultHeaders = [];
        $this->swapped = [];
        $this->clientAddress = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        /** @var \DI\Container $container */
        $container = self::container();

        foreach ($this->swapped as $id => $original) {
            $container->set($id, $original);
        }

        $this->swapped = [];

        // The lookup table is memoised for the life of the instance, and a test
        // that inserts a type inside its transaction must not leak that into
        // the next one.
        $container->get(\App\Models\TypeRepository::class)->forget();

        parent::tearDown();
    }

    // ------------------------------------------------------------ application

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                    (string) $_ENV['DB_HOST'],
                    (int) $_ENV['DB_PORT'],
                    (string) $_ENV['DB_NAME'],
                ),
                (string) $_ENV['DB_USER'],
                (string) $_ENV['DB_PASSWORD'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
        }

        return self::$pdo;
    }

    public static function app(): App
    {
        if (self::$app === null) {
            /** @var \Closure(array<string,mixed>): App $bootstrap */
            $bootstrap = require dirname(__DIR__) . '/src/config/bootstrap.php';

            self::$app = $bootstrap(self::containerOverrides());
        }

        return self::$app;
    }

    public static function container(): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = self::app()->getContainer();

        return $container;
    }

    /**
     * Definitions the test container replaces.
     *
     * The shared PDO is bound here so application code runs inside the same
     * transaction the suite rolls back. Every Domain/Ports interface is bound
     * to a fake in a fragment under tests/Support/Overrides/ — one per slice,
     * mirroring src/config/container/ — so a test that would reach the network
     * fails loudly instead of quietly succeeding.
     *
     * @return array<string,mixed>
     */
    private static function containerOverrides(): array
    {
        $overrides = [
            PDO::class => self::pdo(),
        ];

        foreach (glob(__DIR__ . '/Support/Overrides/*.php') ?: [] as $fragment) {
            /** @var array<string,mixed> $slice */
            $slice = require $fragment;
            $overrides += $slice;
        }

        return $overrides;
    }

    /**
     * Replaces a container entry for the remainder of the current test.
     *
     * Used to swap a port's throwing fake for one returning canned data. The
     * container is shared across the process, so anything swapped here is put
     * back in tearDown().
     */
    protected function swap(string $id, mixed $instance): mixed
    {
        $container = self::container();

        if (!array_key_exists($id, $this->swapped)) {
            $this->swapped[$id] = $container->get($id);
        }

        /** @var \DI\Container $container */
        $container->set($id, $instance);

        return $instance;
    }

    protected function auth(): Auth
    {
        /** @var Auth $auth */
        $auth = self::container()->get(Auth::class);

        return $auth;
    }

    // ------------------------------------------------------------ transaction

    public function beginDatabaseTransaction(): void
    {
        if (self::$transactionDepth === 0) {
            self::pdo()->beginTransaction();
        }

        ++self::$transactionDepth;
    }

    public function rollBackDatabaseTransaction(): void
    {
        --self::$transactionDepth;

        if (self::$transactionDepth === 0 && self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    // --------------------------------------------------------------- requests

    /**
     * The address the next request appears to come from.
     *
     * Fluent rather than another argument on every helper: few tests care, and
     * threading it through get/post/put/delete would put a parameter nobody
     * uses in front of everyone.
     */
    protected function from(string $address): static
    {
        $this->clientAddress = $address;

        return $this;
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @param array<string,mixed>  $query
     */
    protected function request(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): ResponseInterface {
        return self::app()->handle($this->buildRequest($method, $uri, $body, $headers, $query));
    }

    /**
     * @param array<string,mixed>  $query
     * @param array<string,string> $headers
     */
    protected function get(string $uri, array $query = [], array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, [], $headers, $query);
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    protected function post(string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $uri, $body, $headers);
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    protected function put(string $uri, array $body = [], array $headers = []): ResponseInterface
    {
        return $this->request('PUT', $uri, $body, $headers);
    }

    /**
     * @param array<string,string> $headers
     */
    protected function delete(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $uri, [], $headers);
    }

    /**
     * The decoded JSON body of a response.
     *
     * @return array<string,mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     * @param array<string,mixed>  $query
     */
    protected function buildRequest(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
        array $query = [],
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost' . $uri, ['REMOTE_ADDR' => $this->clientAddress])
            ->withQueryParams($query);

        foreach ([...$this->defaultHeaders, ...$headers] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== []) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $request;
    }

    // -------------------------------------------------------------- identities

    /**
     * Signs in through the real Delight\Auth, so AuthMiddleware and
     * PermissionMiddleware run for real rather than against a faked session.
     */
    protected function actingAs(int $userId): static
    {
        [$email, $password] = Factory::credentialsFor($userId);

        $this->auth()->login($email, $password);

        return $this;
    }

    protected function asOwner(): static
    {
        return $this->actingAs(Factory::createUser(Role::Owner));
    }

    protected function asAdministrator(): static
    {
        return $this->actingAs(Factory::createUser(Role::Administrator));
    }

    protected function asUser(): static
    {
        return $this->actingAs(Factory::createUser(Role::User));
    }

    /**
     * A user with no level at all — used to prove a guarded route rejects
     * more than just the lowest level.
     */
    protected function asRoleless(): static
    {
        return $this->actingAs(Factory::createUser(null));
    }

    protected function withCronSecret(?string $secret = null): static
    {
        $this->defaultHeaders['X-Cron-Secret'] = $secret ?? (string) $_ENV['CRON_SECRET'];

        return $this;
    }
}
