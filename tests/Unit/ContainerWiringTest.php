<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Tests\TestCase;

/**
 * The application is assembled from per-slice fragments under
 * src/config/container/, and the test container replaces ports with fakes.
 * That means nothing else in the suite proves the REAL definitions resolve —
 * a controller with a typo'd dependency, or a port left unbound, would ship
 * green.
 *
 * These tests build the production container (only PDO swapped, so no test
 * touches the dev database) and resolve every port, controller and
 * repository through it.
 */
function productionContainer(): ContainerInterface
{
    static $container = null;

    if ($container === null) {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(false);
        // container.php throws on a key collision between fragments, so simply
        // loading it is the check that two slices did not define one service.
        $builder->addDefinitions(dirname(__DIR__, 2) . '/src/config/container.php');
        $builder->addDefinitions([PDO::class => TestCase::pdo()]);

        $container = $builder->build();
    }

    return $container;
}

/**
 * @return list<string>
 */
function classesIn(string $directory, string $namespace): array
{
    $found = [];

    foreach (glob(dirname(__DIR__, 2) . '/src/app/' . $directory . '/*.php') ?: [] as $file) {
        $found[] = $namespace . '\\' . basename($file, '.php');
    }

    sort($found);

    return $found;
}

it('loads every container fragment without a duplicate definition', function (): void {
    expect(productionContainer())->toBeInstanceOf(ContainerInterface::class);
});

it('resolves every port to a real implementation', function (): void {
    $ports = classesIn('Domain/Ports', 'App\Domain\Ports');

    expect($ports)->not->toBeEmpty();

    foreach ($ports as $port) {
        expect(productionContainer()->get($port))->toBeInstanceOf($port);
    }
});

it('resolves every controller and repository', function (): void {
    $classes = [
        ...classesIn('Controllers', 'App\Controllers'),
        ...array_filter(
            classesIn('Models', 'App\Models'),
            static fn (string $class): bool => str_ends_with($class, 'Repository'),
        ),
    ];

    foreach ($classes as $class) {
        if ((new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        expect(productionContainer()->get($class))->toBeInstanceOf($class);
    }
});

it('has a permission guard for every permission', function (): void {
    foreach (App\Domain\Auth\Permission::cases() as $permission) {
        expect(productionContainer()->get('can:' . $permission->value))
            ->toBeInstanceOf(App\Libraries\Middleware\PermissionMiddleware::class);
    }
});
