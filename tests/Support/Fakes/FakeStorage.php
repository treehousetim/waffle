<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use App\Domain\Ports\StoragePort;
use RuntimeException;

/**
 * StoragePort in memory (tests/Support/Overrides/storage.php): every file a
 * test stores is here to look at, and nothing touches the disk.
 */
final class FakeStorage implements StoragePort
{
    /** @var array<string,string> key => bytes */
    public array $files = [];

    public bool $failPuts = false;

    public function put(string $key, string $bytes): void
    {
        if ($this->failPuts) {
            throw new RuntimeException('The fake storage was told to fail.');
        }

        $this->files[$key] = $bytes;
    }

    public function get(string $key): ?string
    {
        return $this->files[$key] ?? null;
    }

    public function delete(string $key): void
    {
        unset($this->files[$key]);
    }
}
