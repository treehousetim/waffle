<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * The module format (docs/modules.md): what MODULE.md and MODULE-TEST.md must
 * hold. tests/Unit/ModulesTest.php and the module command both ask this class,
 * so the format check and an install enforce the same rules (17).
 */
final class Format
{
    public const MODULE_KEYS = ['name', 'title', 'version', 'summary', 'waffle', 'proven-on', 'author', 'license', 'permissions', 'libraries', 'config'];
    public const MODULE_SECTIONS = ['Purpose', 'Roles and permissions', 'Data model and migrations', 'Failure list', 'Installation'];
    public const MODULE_TEST_SECTIONS = ['Desired outcomes', 'How to test', 'Definition of done'];
    public const LEVELS = ['owner', 'administrator', 'user', 'not signed in'];
    public const NAME = '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/';
    public const VERSION = '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\z/';

    /**
     * Everything wrong with a module, as one line each; empty when it passes.
     *
     * @return list<string>
     */
    public static function problems(string $name, string $module, string $test): array
    {
        $problems = [];
        $keys = self::frontMatter($module);

        foreach (self::MODULE_KEYS as $key) {
            if (($keys[$key] ?? '') === '') {
                $problems[] = "MODULE.md: front matter has no '{$key}'";
            }
        }

        if (!preg_match(self::NAME, $name)) {
            $problems[] = "'{$name}' is not a module name: lowercase words joined by hyphens";
        }

        if (isset($keys['name']) && $keys['name'] !== '' && $keys['name'] !== $name) {
            $problems[] = "MODULE.md: name '{$keys['name']}' does not match where it is, '{$name}'";
        }

        if (($keys['version'] ?? '') !== '' && !preg_match(self::VERSION, $keys['version'])) {
            $problems[] = "MODULE.md: version '{$keys['version']}' is not X.Y.Z";
        }

        if (($keys['proven-on'] ?? '') !== '' && !preg_match('/\A(?:none|\d+\.\d+\.\d+(?:, *\d+\.\d+\.\d+)*)\z/', $keys['proven-on'])) {
            $problems[] = "MODULE.md: proven-on '{$keys['proven-on']}' is not 'none' or a list of X.Y.Z";
        }

        if (($keys['waffle'] ?? '') !== '') {
            try {
                Constraint::parse($keys['waffle']);
            } catch (Refusal $refusal) {
                $problems[] = 'MODULE.md: ' . $refusal->getMessage();
            }
        }

        foreach (self::list($keys['permissions'] ?? '') as $permission) {
            if (!preg_match(self::NAME, $permission)) {
                $problems[] = "MODULE.md: permission '{$permission}' is not lowercase words joined by hyphens";
            }
        }

        foreach (self::MODULE_SECTIONS as $heading) {
            if (trim(self::section($module, $heading)) === '') {
                $problems[] = "MODULE.md: no '## {$heading}' section";
            }
        }

        $roles = strtolower(self::section($module, 'Roles and permissions'));

        foreach (self::LEVELS as $level) {
            if (!str_contains($roles, $level)) {
                $problems[] = "MODULE.md: Roles and permissions does not say what '{$level}' may do";
            }
        }

        if (!preg_match('/CREATE TABLE|extends AbstractMigration/', self::section($module, 'Data model and migrations'))) {
            $problems[] = 'MODULE.md: Data model and migrations has no CREATE TABLE or migration class';
        }

        foreach (self::MODULE_TEST_SECTIONS as $heading) {
            if (trim(self::section($test, $heading)) === '') {
                $problems[] = "MODULE-TEST.md: no '## {$heading}' section";
            }
        }

        $failures = self::numbers(self::section($module, 'Failure list'));
        $outcomes = self::section($test, 'Desired outcomes');

        if ($failures === []) {
            $problems[] = 'MODULE.md: the Failure list has no numbered items';
        } elseif ($failures !== range(1, count($failures))) {
            $problems[] = 'MODULE.md: the Failure list is not numbered 1, 2, 3 in order';
        }

        if (self::numbers($outcomes) === []) {
            $problems[] = 'MODULE-TEST.md: Desired outcomes has no numbered items';
        }

        preg_match_all('/\(([\d, ]+)\)/', $outcomes, $cited);
        $covered = [];

        foreach ($cited[1] as $list) {
            foreach (array_map('intval', explode(',', $list)) as $number) {
                $covered[$number] = true;
            }
        }

        $uncovered = array_values(array_diff($failures, array_keys($covered)));

        if ($uncovered !== []) {
            $problems[] = 'MODULE-TEST.md: no desired outcome covers failure ' . implode(', ', $uncovered);
        }

        return $problems;
    }

    /** @return array<string,string> */
    public static function frontMatter(string $text): array
    {
        if (!preg_match('/\A---\n(.*?)\n---\n/s', $text, $match)) {
            return [];
        }

        $keys = [];

        foreach (explode("\n", $match[1]) as $line) {
            if (preg_match('/^([a-z-]+):\s*(.*)$/', $line, $pair)) {
                $keys[$pair[1]] = trim($pair[2]);
            }
        }

        return $keys;
    }

    /** The text of one `## ` section, up to the next. */
    public static function section(string $text, string $heading): string
    {
        return preg_match('/^## ' . preg_quote($heading, '/') . '\s*\n(.*?)(?=^## |\z)/ms', $text, $match) ? $match[1] : '';
    }

    /**
     * A comma-separated front-matter value, `none` being the empty list.
     *
     * @return list<string>
     */
    public static function list(string $value): array
    {
        $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));

        return $items === ['none'] ? [] : $items;
    }

    /** @return list<int> the numbers of a numbered list ("1. ...") */
    private static function numbers(string $section): array
    {
        preg_match_all('/^(\d+)\. /m', $section, $numbers);

        return array_map('intval', $numbers[1]);
    }
}
