<?php

declare(strict_types=1);

namespace App\Domain\Ports;

/**
 * Files the app keeps (uploads, photos, media), by key (docs/storage.md).
 *
 * The real adapter is Libraries/Storage/LocalDiskStorage, under the `storage`
 * volume; object storage can replace it here without touching any caller.
 * Keys are the app's own (lowercase letters, digits, `/`, `-`, `.`), never a
 * name from a request. Tests bind tests/Support/Fakes/FakeStorage.
 */
interface StoragePort
{
    public function put(string $key, string $bytes): void;

    /** The file's bytes, or null when there is none. */
    public function get(string $key): ?string;

    /** Removes the file; nothing happens when there is none. */
    public function delete(string $key): void;
}
