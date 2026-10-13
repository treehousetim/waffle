<?php

declare(strict_types=1);

namespace ModuleTool;

use ZipArchive;

/**
 * Step 5 of `use`: unpack a `zip` module into scratch. Every entry is judged
 * before anything is written, and the size is counted while writing rather
 * than taken from the zip's own claims (10).
 */
final class Zip
{
    public const LIMIT = 10 * 1024 * 1024;

    public static function extract(string $zipFile, string $dest, string $name, int $limit = self::LIMIT): void
    {
        $zip = new ZipArchive();

        if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
            throw new Refusal('the download is not a zip file (10).');
        }

        try {
            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $shown = Terminal::safe($entry);
                $zip->getExternalAttributesIndex($i, $system, $attributes);

                if ($entry === '' || $entry[0] === '/' || preg_match('/\A[A-Za-z]:/', $entry)) {
                    throw new Refusal("the zip has an entry with an absolute path, '{$shown}' (10).");
                }

                if (str_contains($entry, '\\')) {
                    throw new Refusal("the zip has an entry with a backslash, '{$shown}' (10).");
                }

                if (in_array('..', explode('/', $entry), true)) {
                    throw new Refusal("the zip has an entry with a '..' segment, '{$shown}' (10).");
                }

                if (!preg_match('/\A[\x20-\x7E]+\z/', $entry)) {
                    throw new Refusal("the zip has an entry whose name is not printable ASCII, '{$shown}' (12).");
                }

                if (!str_starts_with($entry, $name . '/')) {
                    throw new Refusal("the zip has an entry outside '{$name}/', '{$shown}' (10).");
                }

                if ($system === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0o170000) === 0o120000) {
                    throw new Refusal("the zip has a symlink, '{$shown}' (10).");
                }

                $entries[$i] = $entry;
            }

            $written = 0;

            foreach ($entries as $i => $entry) {
                $target = $dest . '/' . $entry;

                if (str_ends_with($entry, '/')) {
                    is_dir($target) || mkdir($target, 0o755, true);
                    continue;
                }

                is_dir(dirname($target)) || mkdir(dirname($target), 0o755, true);
                $in = $zip->getStream($entry);
                $out = fopen($target, 'wb');

                while (!feof($in)) {
                    $chunk = (string) fread($in, 65536);
                    $written += strlen($chunk);

                    if ($written > $limit) {
                        fclose($in);
                        fclose($out);

                        throw new Refusal(sprintf('the zip unpacks to more than %d MB (10).', intdiv($limit, 1024 * 1024)));
                    }

                    fwrite($out, $chunk);
                }

                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }
}
