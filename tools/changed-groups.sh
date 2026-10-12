#!/usr/bin/env bash
#
# tools/changed-groups.sh — prints the test groups a working-tree change affects.
#
# Used by `tools/test.sh --changed`, and usable on its own:
#
#   ./tools/changed-groups.sh          # e.g. "core settings shared"
#
# The groups are declared in tests/Pest.php and ARE the slices the codebase
# already has — one file each in src/config/container/ and src/config/routes/.
# tests/Arch/ArchGroupsTest.php fails the build if a test file escapes them all,
# so a group here always reaches real tests.
#
# Mapping is by directory rather than by grepping for class names: a path
# belongs to a slice by construction, where a name match is a guess that gets
# wider every time two classes share a word.
#
# `core` is the catch-all for the platform every slice sits on. Anything shared
# — a repository, BaseController, tests/Support, src/config — selects it, and
# prints `shared` as the last word so the caller can say the selection is wide.
#
# ADDING A SLICE: give it a directory under src/app/Domain/<Slice>/ and
# tests/Unit/Domain/<Slice>/, and add one line for each below. Its wiring files
# (src/config/{container,routes}/<slice>.php) are mapped automatically.
#
set -euo pipefail

cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

groups=""
shared=0

changed="$(git diff --name-only HEAD 2>/dev/null || true
           git ls-files --others --exclude-standard 2>/dev/null || true)"

for file in $changed; do
    case "$file" in
        # -- slices: one or two lines each ----------------------------------
        src/app/Domain/Settings/*|tests/Unit/Domain/Settings/*|tests/Feature/SettingsTest.php)
            groups="$groups settings" ;;

        # -- the frontend's Node suite ---------------------------------------
        src/frontend/src/utils/*|src/frontend/test/*) groups="$groups frontend" ;;

        # A slice's wiring names its own group.
        src/config/container/*.php|src/config/routes/*.php|tests/Support/Overrides/*.php)
            groups="$groups $(basename "$file" .php)"
            ;;

        tests/Feature/E2E/*) groups="$groups e2e" ;;
        tests/Arch/*)        groups="$groups arch" ;;
        tests/Schema/*)      groups="$groups schema" ;;

        src/app/*|src/config/*|tests/*) groups="$groups core"; shared=1 ;;
        # Config and scripts the core suite asserts by shape (NginxHeadersTest,
        # DeployScriptsTest, CreateProjectTest, ReleaseTest).
        tools/*|deploy/*|docker/*|docker-compose*.yml|composer.json|CHANGELOG.md)
            groups="$groups core" ;;
    esac
done

# Deduplicated, stable order, one line.
printf '%s' "$(printf '%s\n' $groups | sort -u | tr '\n' ' ')"

if [ "$shared" -eq 1 ]; then
    printf 'shared'
fi

printf '\n'
