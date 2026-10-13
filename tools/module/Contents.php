<?php

declare(strict_types=1);

namespace ModuleTool;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Step 6 of `use` (docs/module-command.md): what may be in a module. Nothing
 * in it has a use but hiding text from the person reviewing it.
 */
final class Contents
{
    /** Refused in text: control characters but tab, LF and CR; Cf; line and paragraph separators; variation selectors (13). */
    private const HIDDEN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}]|\p{Cf}|\p{Zl}|\p{Zp}|[\x{FE00}-\x{FE0F}]|[\x{E0100}-\x{E01EF}]/u';

    /** A file name: printable ASCII only (12). */
    private const NAME = '/\A[\x20-\x7E]+\z/';

    /**
     * @param bool $skipGit leave out a top-level .git/, for a clone dropped
     *                      into modules/ (the command never has one: 8)
     */
    public static function scan(string $dir, bool $skipGit = false): Scan
    {
        $refusals = [];
        $files = [];
        $binaries = [];
        $comments = [];

        $entries = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $entry): bool => !($skipGit && $entry->getPath() === $dir && $entry->getFilename() === '.git'),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $relative = substr($path, strlen($dir) + 1);
            $shown = Terminal::safe($relative);

            if (!preg_match(self::NAME, $entry->getFilename())) {
                $refusals[] = "{$shown}: a file name with something other than printable ASCII (12)";
                continue;
            }

            if (is_link($path)) {
                $refusals[] = "{$relative}: a symlink (11)";
                continue;
            }

            if (is_dir($path)) {
                continue;
            }

            if (!is_file($path)) {
                $refusals[] = "{$relative}: not a regular file (11)";
                continue;
            }

            $files[] = $relative;
            $text = (string) file_get_contents($path);

            if (str_contains($text, "\0")) {
                $binaries[] = $relative;
                continue;
            }

            if (!mb_check_encoding($text, 'UTF-8')) {
                $refusals[] = "{$relative}: neither UTF-8 text nor binary (33)";
                continue;
            }

            foreach (explode("\n", $text) as $index => $line) {
                if (preg_match(self::HIDDEN, $line, $hidden)) {
                    $refusals[] = sprintf('%s:%d: hidden or control character U+%04X (13)', $relative, $index + 1, mb_ord($hidden[0]));
                }
            }

            preg_match_all('/<!--.*?(?:-->|\z)/s', $text, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] as [$comment, $offset]) {
                $comments[] = ['file' => $relative, 'line' => substr_count($text, "\n", 0, $offset) + 1, 'text' => $comment];
            }
        }

        sort($files, SORT_STRING);
        sort($binaries, SORT_STRING);
        usort($comments, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return new Scan($refusals, $files, $binaries, $comments);
    }
}
