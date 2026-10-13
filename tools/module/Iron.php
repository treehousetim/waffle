<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * The catalogue's clone in .waffle/iron/ (docs/module-command.md, step 1).
 * The URL is fixed; a clone pointing elsewhere is refused (1), and so is a
 * main that is not a fast forward of what was there before (2).
 */
final class Iron
{
    public const URL = 'https://github.com/treehousetim/waffle-iron';

    public function __construct(
        private readonly string $dir,
        private readonly Git $git,
        private readonly string $url = self::URL,
    ) {
    }

    public function update(): Catalogue
    {
        if (!is_dir($this->dir . '/.git')) {
            if (file_exists($this->dir)) {
                throw new Refusal("{$this->dir} is in the way and is not the catalogue's clone; remove it (1).");
            }

            is_dir(dirname($this->dir)) || mkdir(dirname($this->dir), 0o755, true);
            $this->git->must(['clone', '--quiet', '--branch', 'main', '--', $this->url, $this->dir], dirname($this->dir), 'could not fetch the catalogue:');
        } else {
            // The configured URL, not `remote get-url`'s, which applies url.*.insteadOf rewrites.
            $origin = trim($this->git->must(['config', '--get', 'remote.origin.url'], $this->dir, 'the catalogue clone has no origin:'));

            if ($origin !== $this->url) {
                throw new Refusal('the catalogue clone in ' . $this->dir . ' fetches from ' . Terminal::safe($origin) . ", not {$this->url}; remove it and run this again (1).");
            }

            $this->git->must(['fetch', '--quiet', 'origin', 'main'], $this->dir, 'could not reach the catalogue:');

            if ($this->git->run(['merge', '--ff-only', '--quiet', 'FETCH_HEAD'], $this->dir)['code'] !== 0
                || trim($this->git->must(['rev-parse', 'HEAD'], $this->dir, '')) !== trim($this->git->must(['rev-parse', 'FETCH_HEAD'], $this->dir, ''))) {
                throw new Refusal("the catalogue's history was rewritten, or the clone was changed: its main is not a fast forward of the one fetched before. Waffle Iron forbids that, so something is wrong; the old clone is kept (2).");
            }
        }

        // From the commit, not the working tree: an edit to the file on disk changes nothing.
        return Catalogue::parse($this->git->must(['show', 'HEAD:index.json'], $this->dir, 'the catalogue has no index.json:'));
    }
}
