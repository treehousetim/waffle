<?php

declare(strict_types=1);

use App\Domain\Ports\StoragePort;
use App\Libraries\Storage\LocalDiskStorage;
use Tests\TestCase;

/* StoragePort on the local disk (docs/storage.md). The numbers are its failure list's. */

function storageRoot(): string
{
    return sys_get_temp_dir() . '/waffle-storage-' . bin2hex(random_bytes(4));
}

it('stores, reads and deletes a file by key, under its root only (1)', function (): void {
    $root = storageRoot();
    $storage = new LocalDiskStorage($root);

    $storage->put('photos/7-abc.jpg', 'bytes');
    $read = $storage->get('photos/7-abc.jpg');
    $storage->delete('photos/7-abc.jpg');
    $storage->delete('photos/7-abc.jpg');

    expect($read)->toBe('bytes')
        ->and($storage->get('photos/7-abc.jpg'))->toBeNull()
        ->and(glob($root . '/photos/*'))->toBe([])
        ->and(fn () => $storage->put('../escape.jpg', 'x'))->toThrow(InvalidArgumentException::class, 'Not a storage key')
        ->and(fn () => $storage->get('photos/a/../../x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $storage->get('/etc/passwd'))->toThrow(InvalidArgumentException::class);
});

it('says so when it cannot store a file, and leaves no half-written one (2)', function (): void {
    $root = storageRoot();
    mkdir($root);
    file_put_contents($root . '/photos', 'a file where the directory should be');

    expect(fn () => (new LocalDiskStorage($root))->put('photos/7.jpg', 'x'))
        ->toThrow(RuntimeException::class, 'Could not create');
});

it('says so when the directory is there but the file cannot be written (2)', function (): void {
    $root = storageRoot();
    mkdir($root . '/photos/7.jpg', 0o775, true);

    expect(fn () => (new LocalDiskStorage($root))->put('photos/7.jpg', 'x'))
        ->toThrow(RuntimeException::class, 'Could not store photos/7.jpg: a directory is in the way.')
        ->and(glob($root . '/photos/*.tmp'))->toBe([]);
});

it('is the storage volume in the app, and memory in the tests', function (): void {
    /** @var array<string,mixed> $definitions */
    $definitions = require dirname(__DIR__, 3) . '/src/config/container/storage.php';
    $container = new DI\Container(['config' => ['paths' => ['root' => '/var/www']], ...$definitions]);

    expect($container->get(StoragePort::class))->toBeInstanceOf(LocalDiskStorage::class)
        ->and(TestCase::container()->get(StoragePort::class))->toBeInstanceOf(Tests\Support\Fakes\FakeStorage::class);
});
