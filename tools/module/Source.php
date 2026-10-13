<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * Step 4 of `use`: exactly the files that were listed, in scratch. A
 * repository is cloned, the pinned commit must be one of its own (7), and the
 * module is a `git archive` export, never a working copy (8).
 */
final class Source
{
    /**
     * @return array{dir:string,hash:?string} the directory to check, and for
     *                                        a zip or a file the hash of the
     *                                        download (a directory's is taken
     *                                        once its contents are checked)
     */
    public static function fetch(Entry $entry, string $scratch, Git $git, Transport $transport): array
    {
        $export = $scratch . '/export';
        is_dir($export) || mkdir($export, 0o755, true);

        if ($entry->form === 'directory') {
            $clone = $scratch . '/clone';
            $commit = $entry->source['commit'];
            $git->must(['clone', '--quiet', '--no-checkout', '--', $entry->source['repository'], $clone], $scratch, 'could not fetch ' . $entry->source['repository'] . ':');

            if ($git->run(['cat-file', '-e', $commit . '^{commit}'], $clone)['code'] !== 0) {
                throw new Refusal("commit {$commit} is not in {$entry->source['repository']}: a commit served only through a fork is refused (7).");
            }

            $branches = trim($git->must(['branch', '-r', '--contains', $commit], $clone, 'could not read the branches:'));
            $tags = trim($git->must(['tag', '--contains', $commit], $clone, 'could not read the tags:'));

            if ($branches === '' && $tags === '') {
                throw new Refusal("commit {$commit} is on none of {$entry->source['repository']}'s own branches or tags (7).");
            }

            $tar = $scratch . '/module.tar';
            $path = $entry->source['path'];
            $git->must(array_merge(['archive', '--format=tar', '-o', $tar, $commit], $path === '.' ? [] : [$path]), $clone, "{$path} is not in {$entry->source['repository']} at {$commit}:");
            self::untar($tar, $export);
            $dir = $path === '.' ? $export : $export . '/' . $path;

            return ['dir' => $dir, 'hash' => null];
        }

        $download = $scratch . '/download';
        Download::fetch($transport, $entry->source['url'], $download);
        $hash = DirectoryHash::ofFile($download);

        if ($entry->form === 'zip') {
            Zip::extract($download, $export, $entry->name);

            return ['dir' => $export . '/' . $entry->name, 'hash' => $hash];
        }

        $dir = $export . '/' . $entry->name;
        mkdir($dir);
        rename($download, $dir . '/' . $entry->name . '.module.md');

        return ['dir' => $dir, 'hash' => $hash];
    }

    private static function untar(string $tar, string $dest): void
    {
        $process = proc_open(['tar', '-x', '-f', $tar, '-C', $dest], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new Refusal('could not unpack the export: ' . Terminal::safe(trim($err)));
        }
    }
}
