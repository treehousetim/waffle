<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * Waffle Iron's index.json (docs/module-command.md, steps 2 and 3).
 */
final class Catalogue
{
    public const SCHEMA = 1;

    /** @param array<string,array<mixed>> $modules */
    private function __construct(private readonly array $modules)
    {
    }

    public static function parse(string $json): self
    {
        $index = json_decode($json, true);

        if (!is_array($index) || !is_int($index['schema'] ?? null) || !is_array($index['modules'] ?? null)) {
            throw new Refusal("the catalogue's index.json is not a catalogue this command can read (3).");
        }

        if ($index['schema'] > self::SCHEMA) {
            throw new Refusal(sprintf('the catalogue is schema %d and this command reads schema %d: update tools/module.sh from Waffle, https://github.com/treehousetim/waffle (3).', $index['schema'], self::SCHEMA));
        }

        if ($index['schema'] < self::SCHEMA) {
            throw new Refusal("the catalogue's index.json is not a catalogue this command can read (3).");
        }

        return new self($index['modules']);
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_map('strval', array_keys($this->modules));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @return array<string,string> title, summary, author, license, as listed */
    public function about(string $name): array
    {
        $module = $this->module($name);
        $about = [];

        foreach (['title', 'summary', 'author', 'license'] as $key) {
            $about[$key] = is_string($module[$key] ?? null) ? $module[$key] : '';
        }

        return $about;
    }

    /**
     * Every listed version, newest first.
     *
     * @return list<Entry>
     */
    public function versions(string $name): array
    {
        $versions = $this->module($name)['versions'] ?? null;

        if (!is_array($versions) || $versions === []) {
            throw new Refusal('the catalogue lists no versions of ' . Terminal::safe($name) . ' (34).');
        }

        $entries = [];

        foreach ($versions as $version => $fields) {
            $entries[] = Entry::from($name, (string) $version, is_array($fields) ? $fields : []);
        }

        usort($entries, static fn (Entry $a, Entry $b): int => version_compare($b->version, $a->version));

        return $entries;
    }

    public function find(string $name, string $version): ?Entry
    {
        if (!isset($this->modules[$name])) {
            return null;
        }

        foreach ($this->versions($name) as $entry) {
            if ($entry->version === $version) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The version to install: the one named, or the newest eligible, and for
     * an upgrade the newest eligible above the installed one (4, 5).
     */
    public function choose(string $name, ?string $version, string $waffleVersion, ?string $above = null): Entry
    {
        $entries = $this->versions($name);

        if ($version !== null) {
            foreach ($entries as $entry) {
                if ($entry->version === $version) {
                    $why = $entry->ineligible($waffleVersion);

                    if ($why !== null) {
                        throw new Refusal("{$name} {$version} cannot be installed: {$why} (5).");
                    }

                    return $entry;
                }
            }

            throw new Refusal("the catalogue does not list {$name} {$version} (5).");
        }

        $reasons = [];

        foreach ($entries as $entry) {
            if ($above !== null && version_compare($entry->version, $above, '<=')) {
                $reasons[] = "{$entry->version}: not newer than the installed {$above}";
                continue;
            }

            $why = $entry->ineligible($waffleVersion);

            if ($why === null) {
                return $entry;
            }

            $reasons[] = "{$entry->version}: {$why}";
        }

        throw new Refusal("no version of {$name} can be installed here (4):\n  " . implode("\n  ", $reasons));
    }

    /** @return array<mixed> */
    private function module(string $name): array
    {
        $module = $this->modules[$name] ?? null;

        if (!is_array($module)) {
            throw new Refusal('the catalogue has no module called ' . Terminal::safe($name) . '.');
        }

        return $module;
    }
}
