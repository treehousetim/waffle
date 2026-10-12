#!/usr/bin/env bash
#
# tools/rename-project.sh — make this template yours. Run once, right after
# copying it, before the first `docker compose up`.
#
#   ./tools/rename-project.sh <slug> "<Display Name>" <hostname>
#   ./tools/rename-project.sh acme "Acme Ops" ops.acme.com
#
# Replaces three placeholders everywhere outside vendor/, node_modules/ and the
# database directory:
#
#   myapp            the slug: database names (myapp, myapp_test), the Doppler
#                    project, /opt/myapp on the server, composer's package name
#   MyApp            the name people read: page titles, the hello message
#   app.example.com  the production hostname nginx's certificate is issued for
#
# The slug must be lowercase letters, digits and underscores, starting with a
# letter, because it becomes a MariaDB database name and the test database is
# "<slug>_test" (tests/bootstrap.php refuses anything not ending in _test).
#
# Run it before the database is created: an existing docker/data/ was made
# under the old names. If one exists, the script stops and says so.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [ "$#" -ne 3 ]; then
    sed -n '4,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 2
fi

SLUG="$1"
NAME="$2"
HOST="$3"

if [[ ! "$SLUG" =~ ^[a-z][a-z0-9_]{1,30}$ ]]; then
    echo "error: the slug must be lowercase letters, digits and underscores, starting with a letter." >&2
    exit 2
fi

if [[ "$NAME" == *"|"* ]] || [[ "$HOST" == *"|"* ]]; then
    echo "error: neither the name nor the hostname may contain '|'." >&2
    exit 2
fi

if [ -d docker/data/mysql ] && [ -n "$(ls -A docker/data/mysql 2>/dev/null)" ]; then
    echo "error: docker/data/mysql already holds a database created under the old names." >&2
    echo "       stop the stack and remove it first: docker compose down && rm -rf docker/data" >&2
    exit 1
fi

FILES="$(grep -rIl --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
              --exclude-dir=data --exclude-dir=coverage --exclude=rename-project.sh \
              -e 'myapp' -e 'MyApp' -e 'app\.example\.com' . || true)"

if [ -z "$FILES" ]; then
    echo "nothing to rename: no placeholder is left."
    exit 0
fi

for file in $FILES; do
    # Hostname first: it contains no other placeholder, but the order makes the
    # result independent of what the name or slug happen to be.
    sed -i.rename-bak \
        -e "s|app\.example\.com|${HOST}|g" \
        -e "s|MyApp|${NAME}|g" \
        -e "s|myapp|${SLUG}|g" \
        "$file"
    rm -f "$file.rename-bak"
    echo "  renamed in ${file#./}"
done

echo
echo "Done. Next:"
echo "  - refresh the lock files (both name the project):"
echo "      (cd src/frontend && npm install --package-lock-only)"
echo "      docker compose up -d && docker compose exec php composer update --lock"
echo "  - create the Doppler project '${SLUG}' with configs dev, prd and prod-deploy (docs/fresh-install.md)"
echo "  - read AGENTS.md, then fill in section 1 with what you are building"
