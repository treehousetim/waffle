<?php

declare(strict_types=1);

namespace App\Libraries\Storage;

use App\Domain\Ports\StoragePort;
use InvalidArgumentException;
use RuntimeException;

/**
 * StoragePort on the local disk: the `storage_data` volume, mounted at
 * /var/www/storage in every stack, outside the code a deploy replaces
 * (docs/storage.md).
 *
 * A file is written to a temporary name and renamed into place, so a reader
 * never sees half of one (18.).
 */
final readonly class LocalDiskStorage implements StoragePort
{
    public function __construct(private string $root)
    {
    }

    public function put(string $key, string $bytes): void
    {
        $path = $this->path($key);
        $directory = dirname($path);

        // Checked first, so a failure is an exception that says what, never a
        // PHP warning from mkdir() or rename().
        if (file_exists($directory) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}: a file is in the way.");
        }

        if (is_dir($path)) {
            throw new RuntimeException("Could not store {$key}: a directory is in the way.");
        }

        // Every step's result is checked: PHP only warns when it cannot write
        // (a volume the app's user does not own), and a warning is not an
        // answer. Without this the caller is told the file was stored.
        if (!is_dir($directory) && !self::quietly(static fn (): bool => mkdir($directory, 0o775, true)) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}: can the app's user write to the storage volume?");
        }

        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (!self::quietly(static fn (): bool => file_put_contents($temporary, $bytes) !== false && rename($temporary, $path))) {
            self::quietly(static fn (): bool => !is_file($temporary) || unlink($temporary));

            throw new RuntimeException("Could not store {$key}: can the app's user write to the storage volume?");
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Runs a file operation with PHP's warnings turned into its answer: false
     * when it failed, and nothing printed or logged on the way.
     *
     * @param \Closure(): bool $operation
     */
    private static function quietly(\Closure $operation): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /** Keys are the app's own; anything that could leave the root is refused. */
    private function path(string $key): string
    {
        if (preg_match('#^[a-z0-9][a-z0-9-]*(/[a-z0-9][a-z0-9.-]*)*$#', $key) !== 1 || str_contains($key, '..')) {
            throw new InvalidArgumentException("Not a storage key: {$key}");
        }

        return rtrim($this->root, '/') . '/' . $key;
    }
}
