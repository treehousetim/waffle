<?php

declare(strict_types=1);

/**
 * Builds the PHP-DI container: the one place every entry point (bootstrap.php,
 * and any CLI you add under src/cli/) gets its services from, so they can
 * never be wired differently.
 *
 * No .env, anywhere: container.php reads configuration from the environment
 * Doppler injected, falling back to getenv() so the CLI sees it too.
 */

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

return static function (array $overrides = []): ContainerInterface {
    $builder = new ContainerBuilder();

    // No autowiring: every service is listed explicitly in container.php.
    $builder->useAutowiring(false);
    $builder->addDefinitions(__DIR__ . '/container.php');

    if ($overrides !== []) {
        $builder->addDefinitions($overrides);
    }

    return $builder->build();
};
