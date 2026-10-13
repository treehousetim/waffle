<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * The module hash (docs/modules.md, "Files and the hash"). For a directory, the
 * same bytes as
 *
 *   find . -type f -print0 | LC_ALL=C sort -z | xargs -0 shasum -a 256 | shasum -a 256
 *
 * run inside it; for a .zip or a .module.md, the file's own SHA-256.
 */
final class DirectoryHash
{
    /** @param list<string> $files relative paths of every file, as Contents lists them */
    public static function of(string $dir, array $files): string
    {
        $paths = array_map(static fn (string $file): string => './' . $file, $files);
        sort($paths, SORT_STRING);
        $lines = '';

        foreach ($paths as $path) {
            $lines .= hash_file('sha256', $dir . '/' . substr($path, 2)) . '  ' . $path . "\n";
        }

        return hash('sha256', $lines);
    }

    public static function ofFile(string $file): string
    {
        return (string) hash_file('sha256', $file);
    }
}
