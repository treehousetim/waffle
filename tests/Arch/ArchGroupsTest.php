<?php

declare(strict_types=1);

/*
 * Every test file belongs to a group.
 *
 * The groups in tests/Pest.php are what make a partial run possible —
 * `./tools/test.sh --group=settings` instead of all of everything. A file
 * outside every group still runs in the full suite, so nothing fails; it just
 * stops being reachable by the fast path, silently, for ever. That is precisely
 * the kind of rot a test should catch rather than a convention should hope for.
 *
 * Arch/ rather than Unit/ because it is a rule about the shape of the suite,
 * and it needs no database.
 */

/**
 * The paths named in every `uses()->group(...)->in(...)` call, expanded to the
 * test files they cover.
 *
 * @return array<string,list<string>> group => test files
 */
function groupedFiles(): array
{
    $pest = (string) file_get_contents(__DIR__ . '/../Pest.php');
    $root = realpath(__DIR__ . '/..');

    /*
     * Comments are stripped before matching, and that is not cosmetic: the
     * pattern accepts quoted paths only, so a `//` line inside an `in(...)`
     * list used to end the match there and silently drop every path after it --
     * reporting files as ungrouped that were grouped a line above. Tolerating
     * comments inside the pattern itself was worse, because an apostrophe in
     * one ("the web server's headers") then reads as a path delimiter.
     *
     * A checker whose own parser fails quietly is worse than no checker, and
     * these lists are exactly where somebody wants to explain a choice.
     */
    $pest = (string) preg_replace('~^\s*//[^\n]*$~m', '', $pest);

    preg_match_all(
        "/->group\('([a-z0-9_]+)'\)\s*->in\(\s*((?:'[^']+',?\s*)+)\)/",
        $pest,
        $calls,
        PREG_SET_ORDER,
    );

    $grouped = [];

    foreach ($calls as $call) {
        preg_match_all("/'([^']+)'/", $call[2], $paths);

        foreach ($paths[1] as $path) {
            $absolute = $root . '/' . $path;

            if (is_dir($absolute)) {
                /** @var list<string> $found */
                $found = glob($absolute . '/{,*/,*/*/}*Test.php', GLOB_BRACE) ?: [];

                foreach ($found as $file) {
                    $grouped[$call[1]][] = $file;
                }

                continue;
            }

            $grouped[$call[1]][] = $absolute;
        }
    }

    return $grouped;
}

it('has a group for every test file', function (): void {
    $root = realpath(__DIR__ . '/..');

    /** @var list<string> $all */
    $all = glob($root . '/{Unit,Feature,Schema,Arch}/{,*/,*/*/}*Test.php', GLOB_BRACE) ?: [];

    $covered = [];

    foreach (groupedFiles() as $files) {
        foreach ($files as $file) {
            $covered[$file] = true;
        }
    }

    $ungrouped = array_values(array_filter(
        $all,
        static fn (string $file): bool => !isset($covered[$file]),
    ));

    $relative = array_map(
        static fn (string $file): string => str_replace($root . '/', '', $file),
        $ungrouped,
    );

    expect($relative)->toBe([], sprintf(
        "These test files are in no group, so no partial run reaches them.\n"
        . "Add them to a uses()->group(...)->in(...) call in tests/Pest.php:\n  %s",
        implode("\n  ", $relative),
    ));
});

it('names only paths that exist', function (): void {
    // A renamed or deleted file leaves a path behind that silently covers
    // nothing, and the group quietly shrinks.
    $missing = [];

    foreach (groupedFiles() as $group => $files) {
        foreach ($files as $file) {
            if (!file_exists($file)) {
                $missing[] = $group . ' -> ' . $file;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('groups the slices the rest of the codebase declares', function (): void {
    /*
     * The groups are not a taxonomy somebody invented for the tests: they are
     * the slices that already have a file each in src/config/container/ and
     * src/config/routes/. Keeping them in step means a new feature slice has an
     * obvious home, and `--group=<slice>` works without anybody looking it up.
     *
     * `core` and `arch` are the two that have no container fragment, which is
     * correct — they are the platform underneath the slices, and the rules about
     * the code itself.
     */
    $slices = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(__DIR__ . '/../../src/config/container/*.php') ?: [],
    );

    $groups = array_keys(groupedFiles());

    expect(array_diff($slices, $groups))->toBe(
        [],
        'Every container slice should have a test group of the same name.',
    );
});
