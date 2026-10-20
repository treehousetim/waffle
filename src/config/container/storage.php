<?php

declare(strict_types=1);

/**
 * Files the app keeps (docs/storage.md): StoragePort on the storage_data
 * volume, mounted at /var/www/storage in every stack.
 */

use App\Domain\Ports\StoragePort;
use App\Libraries\Storage\LocalDiskStorage;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    StoragePort::class => factory(static function (ContainerInterface $c): StoragePort {
        /** @var array{paths:array{root:string}} $config */
        $config = $c->get('config');

        return new LocalDiskStorage($config['paths']['root'] . '/storage');
    }),
];
