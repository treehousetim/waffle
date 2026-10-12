#!/usr/bin/env bash
#
# tools/composer.sh — the only way Composer is run (docs/supply-chain.md).
#
#   ./tools/composer.sh install                  # exactly the lock
#   ./tools/composer.sh update                   # newer versions, a week old or more
#   ./tools/composer.sh require vendor/package   # a new dependency (approval first, AGENTS.md §2)
#
# Runs Composer in the php service's container (`docker compose run --rm
# --no-deps php`): the app's own PHP and extensions, the repository mounted,
# vendor/ in its volume, and none of your home directory. After anything that
# picks versions it checks composer.lock: a package younger than
# COMPOSER_COOLDOWN_DAYS (default 7) puts composer.json and composer.lock back
# and is named. COMPOSER_COOLDOWN_DAYS=0 lets it in and records it in
# cooldown-exceptions.txt, which the test suite honours.
#
# Runs on macOS, Linux and WSL2. The numbers cite the Composer failure list.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DOCKER="${COMPOSER_DOCKER:-docker}"
GATE_DAYS=7

die()  { printf 'error: %s\n' "$1" >&2; exit "${2:-1}"; }
warn() { printf 'warning: %s\n' "$1" >&2; }

case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) die "native Windows shells are not supported; run this inside WSL2 (wsl --install)." ;;   # (12)
esac

if [ "$#" -eq 0 ] || [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    sed -n '3,7p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 0
fi

# (11) A number of days; never a silent "no window".
COOLDOWN="${COMPOSER_COOLDOWN_DAYS-$GATE_DAYS}"
[[ "$COOLDOWN" =~ ^[0-9]+$ ]] || die "COMPOSER_COOLDOWN_DAYS must be a whole number of days (0 records an urgent fix); got '${COOLDOWN}'." 2

command -v "$DOCKER" >/dev/null 2>&1 || die "Docker is needed: Composer runs in the php container (docs/supply-chain.md)."

# (9) The php service: repository and vendor volume mounted, no home directory.
# ${TTY[@]+...}: bash 3.2 calls an empty array unbound under set -u.
in_php() { "$DOCKER" compose run --rm --no-deps ${TTY[@]+"${TTY[@]}"} php "$@"; }
TTY=(-T)
[ -t 0 ] && [ -t 1 ] && TTY=()

# (10) Composer runs as root in there; give the two files it writes back.
give_back() { in_php chown "$(id -u):$(id -g)" composer.json composer.lock >/dev/null 2>&1 || true; }

# Does this command pick versions? `update --lock` only rehashes.
RESOLVES=0
case "$1" in
    require|update|upgrade|u|remove|rm)
        RESOLVES=1
        for arg in "$@"; do [ "$arg" = "--lock" ] && RESOLVES=0; done ;;
esac

if [ "$RESOLVES" -eq 0 ]; then
    status=0
    in_php composer "$@" || status=$?
    give_back
    exit "$status"
fi

# (5) Keep the files as they were, to put back if the result is refused.
SNAPSHOT="$(mktemp -d)"
trap 'rm -rf "$SNAPSHOT"' EXIT
cp composer.json composer.lock "$SNAPSHOT/"

status=0
in_php composer "$@" || status=$?
give_back
[ "$status" -eq 0 ] || exit "$status"

if [ "$COOLDOWN" -eq 0 ]; then   # (4)
    warn "the cooldown is OFF (COMPOSER_COOLDOWN_DAYS=0): anything this brought in under ${GATE_DAYS} days old is recorded in cooldown-exceptions.txt. Commit that file with the lock; delete an entry once its week has passed."
    in_php php tools/composer-age.php --days "$GATE_DAYS" --record
    exit 0
fi

# (1) Anything younger than the window puts everything back.
young=""
age_status=0
young="$(in_php php tools/composer-age.php --days "$COOLDOWN")" || age_status=$?

if [ "$age_status" -ne 0 ]; then
    cp "$SNAPSHOT/composer.json" "$SNAPSHOT/composer.lock" .
    in_php composer install --no-interaction >/dev/null 2>&1 || warn "vendor/ could not be put back; run ./tools/composer.sh install"
    printf 'error: this would bring in releases younger than %s days, so composer.json and composer.lock are back as they were:\n' "$COOLDOWN" >&2
    printf '%s\n' "$young" | sed 's/^/         /' >&2
    printf '       Wait until they are older, or, for a fix that cannot wait: COMPOSER_COOLDOWN_DAYS=0 ./tools/composer.sh %s\n' "$*" >&2
    exit 1
fi
