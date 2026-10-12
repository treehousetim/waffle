#!/usr/bin/env bash
#
# tools/rename-project.sh — make Waffle yours. Run once, before the first
# `docker compose up`. `composer create-project treehousetim/waffle <dir>` runs
# it for you after asking three questions (tools/installer/CreateProject.php).
#
#   ./tools/rename-project.sh <slug> "<Display Name>" <hostname>
#   ./tools/rename-project.sh acme "Acme Ops" ops.acme.com
#
# Replaces the three names Waffle ships under, everywhere outside vendor/,
# node_modules/, .git/, docker/data/ and coverage/:
#
#   waffle              the slug: database names (waffle, waffle_test), the
#                       Doppler project, /opt/waffle on the server
#   Waffle              the name people read: page titles, the hello message
#   waffle.example.com  the production hostname Caddy gets the certificate for
#
# then gives composer.json the project's own package name and description, and
# turns README's backronym into a line saying the project is built on Waffle.
# Lines containing `treehousetim/waffle` point at the framework and are left
# alone. docs/create-project.md has the details and the failure list.
#
# The slug must be lowercase letters, digits and underscores, starting with a
# letter, because it becomes a MariaDB database name and the test database is
# "<slug>_test" (tests/bootstrap.php refuses anything not ending in _test).
# The display name is plain words: it lands in single-quoted PHP, JSON, HTML
# and a sed expression, so nothing that means something in any of them.
#
# Run it before the database is created: an existing docker/data/ was made
# under the old names. If one exists, the script stops and says so.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ "$#" -ne 3 ]; then
    sed -n '7,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 2
fi

SLUG="$1"
NAME="$2"
HOST="$3"

# Every check happens before any file is touched (failure list 8).
if [[ ! "$SLUG" =~ ^[a-z][a-z0-9_]{1,30}$ ]]; then
    echo "error: the slug must be 2-31 lowercase letters, digits and underscores, starting with a letter." >&2
    exit 2
fi

# In a variable, not inline: bash quotes an escaped space inside [[ =~ ]] by
# adding a backslash to the bracket expression, which would then allow one.
NAME_RE='^[A-Za-z0-9][A-Za-z0-9 ._-]{0,59}$'
if [[ ! "$NAME" =~ $NAME_RE ]]; then
    echo "error: the display name must be 1-60 letters, digits, spaces, dots, dashes and underscores, starting with a letter or digit." >&2
    exit 2
fi

LABEL='[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?'
if [[ ! "$HOST" =~ ^${LABEL}(\.${LABEL})+$ ]]; then
    echo "error: the hostname must be a lowercase DNS name with at least one dot, e.g. ops.acme.com." >&2
    exit 2
fi

if [ -d docker/data/mysql ] && [ -n "$(ls -A docker/data/mysql 2>/dev/null)" ]; then
    echo "error: docker/data/mysql already holds a database created under the old names." >&2
    echo "       stop the stack and remove it first: docker compose down && rm -rf docker/data" >&2
    exit 1
fi

# Lines that point at the framework itself, and files that describe the rename.
KEEP='treehousetim/waffle'
EXCLUDED='^(\./)?(tools/rename-project\.sh|tools/setup\.sh|tools/installer/CreateProject\.php|docs/create-project\.md|LICENSE)$'

# Files with a placeholder on a line that is not a framework pointer.
FILES=""
for file in $(grep -rIl --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
                   --exclude-dir=data --exclude-dir=coverage \
                   -e 'waffle' -e 'Waffle' . || true); do
    if [[ "$file" =~ $EXCLUDED ]]; then
        continue
    fi
    # Not grep -q: it exits at the first match, the first grep then dies of
    # SIGPIPE, and under pipefail the file would be skipped.
    if grep -v -e "$KEEP" "$file" | grep -e 'waffle' -e 'Waffle' >/dev/null; then
        FILES="$FILES $file"
    fi
done

if [ -z "$FILES" ]; then
    echo "nothing to rename: no placeholder is left."
    exit 0
fi

for file in $FILES; do
    # Hostname first: the order then cannot depend on what the name or slug
    # happen to be. The validation above means none of the three can contain
    # a character sed would read as anything but itself.
    sed -i.rename-bak \
        -e "/${KEEP//\//\\/}/!{" \
        -e "s|waffle\.example\.com|${HOST}|g" \
        -e "s|Waffle|${NAME}|g" \
        -e "s|waffle|${SLUG}|g" \
        -e "}" \
        "$file"
    rm -f "$file.rename-bak"
    echo "  renamed in ${file#./}"
done

# composer.json describes Waffle on Packagist; the project gets its own. The
# name line still reads treehousetim/waffle, because the pass above keeps it.
if [ -f composer.json ]; then
    sed -i.rename-bak \
        -e "s|\"name\": \"treehousetim/waffle\"|\"name\": \"${SLUG}/${SLUG}\"|" \
        -e "s|\"description\": \"[^\"]*\"|\"description\": \"${NAME}\"|" \
        composer.json
    rm -f composer.json.rename-bak
fi

# README's backronym is Waffle's, not the project's. Written after the pass
# above and containing $KEEP, so no later run renames it.
if [ -f README.md ]; then
    sed -i.rename-bak \
        -e 's|^\*\*W\*\*affle \*\*A\*\*pplication .*$|Built on [Waffle](https://github.com/treehousetim/waffle), the Waffle Application Framework For Lazy Engineers.|' \
        README.md
    rm -f README.md.rename-bak
fi

# A new project starts its own release stream (docs/releasing.md): VERSION 0,
# never released, and a fresh changelog whose first line records the framework
# version it started from. Written after the pass above, and the line names
# treehousetim/waffle, so no later run renames it.
STARTED_FROM="$(tr -d ' \t\r\n' < VERSION 2>/dev/null || true)"
if [[ "$STARTED_FROM" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    ORIGIN_LINE="Started from [treehousetim/waffle v${STARTED_FROM}](https://github.com/treehousetim/waffle/releases/tag/v${STARTED_FROM})."
else
    ORIGIN_LINE="Started from [treehousetim/waffle](https://github.com/treehousetim/waffle)."
fi
printf '0\n' > VERSION
cat > CHANGELOG.md <<CHANGELOG
# Changelog

All notable changes to this project are recorded here, in the format of
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Releases are numbered
1, 2, 3: \`./tools/release.sh\` cuts the next one (docs/releasing.md).

${ORIGIN_LINE}

## [Unreleased]
CHANGELOG
echo "  started VERSION at 0 and a fresh CHANGELOG.md"

echo
echo "Done. Next:"
echo "  - refresh the lock files (both name the project):"
echo "      (cd src/frontend && npm install --package-lock-only)"
echo "      docker compose up -d && docker compose exec php composer update --lock"
echo "  - create the Doppler project '${SLUG}' with configs prod-serve and prod-deploy (docs/fresh-install.md)"
echo "  - read AGENTS.md, then fill in section 1 with what you are building"
