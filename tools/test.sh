#!/usr/bin/env bash
#
# tools/test.sh — the only supported way to run the test suite.
#
#   ./tools/test.sh                        # whole suite
#   ./tools/test.sh --coverage             # whole suite, enforced at 100%
#   ./tools/test.sh tests/Feature/AuthTest.php
#   ./tools/test.sh --filter=login
#   ./tools/test.sh --coverage tests/Unit  # coverage report, no minimum
#   ./tools/test.sh --changed              # only the tests your edits touch
#   ./tools/test.sh --group=settings       # one slice
#   ./tools/test.sh --group=frontend       # only the dashboard's Node suite
#
# It brings Compose up if it is not already running, waits for MariaDB, makes
# sure the `waffle_test` database exists and the app user can reach it, makes sure
# dev dependencies are installed, optionally installs pcov, then runs Pest
# inside the `php` container and exits with Pest's exit code.
#
# Tests never touch Doppler: tests/bootstrap.php loads .env.testing only, and
# the credentials there match the local defaults in docker-compose.yml.
#
set -euo pipefail

# --------------------------------------------------------------------------
# 0. Always operate from the repo root, however the script was invoked.
# --------------------------------------------------------------------------
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Name of the database the suite runs against. tests/bootstrap.php refuses to
# run unless the name ends in `_test`, so this must too.
TEST_DB="waffle_test"

# How long to wait for MariaDB to report healthy, and for a first-run
# `composer install` inside the php container to finish.
DB_WAIT_SECONDS=120
VENDOR_WAIT_SECONDS=180

# --------------------------------------------------------------------------
# 1. Parse arguments.
#
# --coverage is ours; everything else is handed to Pest untouched. If the user
# named a path or a --filter we are running a subset, so the 100% minimum does
# not apply — it would fail on every partial run.
# --------------------------------------------------------------------------
COVERAGE=0
CHANGED=0
PEST_ARGS=()

for arg in "$@"; do
    case "$arg" in
        --coverage)
            COVERAGE=1
            ;;
        --changed)
            CHANGED=1
            ;;
        -h|--help)
            sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,2\} \{0,1\}//'
            exit 0
            ;;
        *)
            PEST_ARGS+=("$arg")
            ;;
    esac
done

# Note: bash 3.2 (the system bash on macOS) treats "${ARR[@]}" as unbound when
# the array is empty and `set -u` is on, hence the ${ARR[@]+"${ARR[@]}"} guard
# used at the point of expansion below.
#
# --------------------------------------------------------------------------
# 1b. --changed: run the groups your edits can have affected.
#
# The full suite grows, and almost all of its time is bcrypt (see AGENTS.md),
# so the answer is to run less of it. Every test file belongs to at least one
# group declared in tests/Pest.php, and those groups ARE the slices the codebase
# already declares -- one file each in src/config/container/ and
# src/config/routes/. tests/Arch/ArchGroupsTest.php fails the build if a test
# file escapes all of them.
#
# So a changed path maps to a group by directory, declaratively, rather than by
# grepping for a class name and hoping. `core` is the catch-all for the platform
# every slice sits on, and anything shared (tests/Support, src/config) selects
# it along with everything else, because a change there can break anything.
#
# Deliberately NOT a substitute for the full gate: it does not enforce the 100%
# ratchet. Run `./tools/test.sh --coverage` before committing.
# --------------------------------------------------------------------------
if [ "$CHANGED" -eq 1 ]; then
    SELECTION="$("$ROOT/tools/changed-groups.sh")"

    # The helper appends `shared` when something platform-wide changed, so the
    # selection can be reported as wide without being treated as a group.
    SHARED=0
    case "$SELECTION" in
        *shared*)
            SHARED=1
            SELECTION="$(printf '%s' "$SELECTION" | sed 's/ *shared$//')"
            ;;
    esac

    if [ -z "$(printf '%s' "$SELECTION" | tr -d ' ')" ]; then
        echo "==> --changed: nothing under src/ or tests/ has changed."
        exit 0
    fi

    echo "==> --changed selected group(s): $SELECTION"

    if [ "$SHARED" -eq 1 ]; then
        echo "    note: a shared or platform file changed, so this includes 'core' and may"
        echo "          still be incomplete. Run ./tools/test.sh --coverage before committing."
    fi

    for group in $SELECTION; do
        PEST_ARGS+=("--group=$group")
    done
fi

# A path or --filter was supplied -> targeted run -> no coverage minimum.
TARGETED=0
if [ ${#PEST_ARGS[@]} -gt 0 ]; then
    TARGETED=1
fi

# --------------------------------------------------------------------------
# 2. Bring the stack up if `db` or `php` is not already running.
# --------------------------------------------------------------------------
RUNNING="$(docker compose ps --services --status running 2>/dev/null || true)"

if ! printf '%s\n' "$RUNNING" | grep -qx 'db' \
   || ! printf '%s\n' "$RUNNING" | grep -qx 'php'; then
    echo "==> starting Compose (db and/or php not running)"
    docker compose up -d
else
    echo "==> Compose already running"
fi

# --------------------------------------------------------------------------
# 3. Wait for the db container's healthcheck to pass.
#
# `depends_on: service_healthy` covers php's startup, but `docker compose exec`
# below talks to db directly, so wait here too.
# --------------------------------------------------------------------------
echo "==> waiting for MariaDB to become healthy (up to ${DB_WAIT_SECONDS}s)"

DB_CID="$(docker compose ps -q db || true)"
if [ -z "$DB_CID" ]; then
    echo "error: the db container does not exist. Try: docker compose up -d db" >&2
    exit 1
fi

waited=0
while :; do
    # Missing .State.Health means the container has no healthcheck (or is gone);
    # `|| true` keeps `set -e` from killing us mid-poll.
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
              "$DB_CID" 2>/dev/null || echo unknown)"

    case "$health" in
        healthy)
            break
            ;;
        none)
            echo "error: the db container reports no healthcheck; check docker-compose.yml" >&2
            exit 1
            ;;
    esac

    if [ "$waited" -ge "$DB_WAIT_SECONDS" ]; then
        echo "error: MariaDB did not become healthy within ${DB_WAIT_SECONDS}s (last status: ${health})." >&2
        echo "       inspect it with: docker compose logs db" >&2
        exit 1
    fi

    sleep 2
    waited=$((waited + 2))
done

echo "    MariaDB healthy after ${waited}s"

# --------------------------------------------------------------------------
# 4. Load credentials.
#
# Tests never read Doppler and never read a .env. Credentials for creating the
# test database are the same defaults docker-compose.yml falls back to, which
# is what lets a fresh clone run the suite with no secrets at all.

DB_USER="${DB_USER:-waffle}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-root}"

# --------------------------------------------------------------------------
# 5. Create the test database and grant the app user. Idempotent: safe to run
#    on every invocation, and it never touches the dev database.
# --------------------------------------------------------------------------
echo "==> ensuring database \`${TEST_DB}\` exists and \`${DB_USER}\` can use it"
docker compose exec -T db mariadb -uroot -p"${DB_ROOT_PASSWORD}" -e "
    CREATE DATABASE IF NOT EXISTS ${TEST_DB}
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL ON ${TEST_DB}.* TO '${DB_USER}'@'%';
    FLUSH PRIVILEGES;
"

# --------------------------------------------------------------------------
# 6. Make sure dev dependencies are installed inside the container.
#
# The php service's startup command is `composer install && exec php-fpm`, so
# PID 1 is the shell until that install finishes and becomes php-fpm after. That
# makes it an exact readiness signal — and waiting for it matters: starting a
# second composer install while the first is still running breaks both (they
# share the download cache: "corrupted zip archive"), the startup one fails,
# the container exits, and this script's install dies with it (exit 137).
#
# PID 1's *name* (/proc/1/comm), not its command line: the shell's command
# line contains the words "exec php-fpm" from the very start.
# --------------------------------------------------------------------------
php_ready() {
    [ "$(docker compose exec -T php cat /proc/1/comm 2>/dev/null)" = "php-fpm" ]
}

have_pest() {
    docker compose exec -T php test -x vendor/bin/pest >/dev/null 2>&1
}

if ! php_ready; then
    echo "==> waiting for the php container's startup \`composer install\` (up to ${VENDOR_WAIT_SECONDS}s)"
    waited=0
    while ! php_ready; do
        if [ "$waited" -ge "$VENDOR_WAIT_SECONDS" ]; then
            echo "warning: php-fpm still is not running after ${VENDOR_WAIT_SECONDS}s." >&2
            echo "         continuing anyway; if this run fails, see: docker compose logs php" >&2
            break
        fi
        sleep 3
        waited=$((waited + 3))
    done
fi

if ! have_pest; then
    echo "==> installing composer dependencies (vendor/bin/pest missing)"
    docker compose exec -T php composer install --no-interaction
fi

if ! have_pest; then
    echo "error: vendor/bin/pest still missing after composer install." >&2
    echo "       check: docker compose logs php" >&2
    exit 1
fi

# --------------------------------------------------------------------------
# 7. Coverage driver. pcov is installed into the running container on first use
#    rather than baked into the image, so production images stay lean. The
#    `php -m` check is the guard: it makes this a no-op on every later run.
#
#    Note this is lost when the container is recreated (not merely restarted),
#    at which point the next --coverage run reinstalls it.
# --------------------------------------------------------------------------
if [ "$COVERAGE" -eq 1 ]; then
    if docker compose exec -T php php -m 2>/dev/null | grep -qi '^pcov$'; then
        echo "==> pcov already present"
    else
        echo "==> installing pcov into the running php container (first --coverage run)"
        docker compose exec -T -u root php sh -c 'pecl install pcov && docker-php-ext-enable pcov'

        # Pest runs as a fresh CLI process, which rescans conf.d, so no restart
        # is needed — but verify rather than assume.
        if ! docker compose exec -T php php -m 2>/dev/null | grep -qi '^pcov$'; then
            echo "error: pcov was installed but PHP still does not load it." >&2
            echo "       try: docker compose restart php && ./tools/test.sh --coverage" >&2
            exit 1
        fi
        echo "    pcov enabled"
    fi
fi

# --------------------------------------------------------------------------
# 8. Run Pest.
#
# Whole suite + --coverage  -> enforce the 100% ratchet.
# Targeted run + --coverage -> report only; a subset can never reach 100%.
# --------------------------------------------------------------------------
RUN_ARGS=()
if [ ${#PEST_ARGS[@]} -gt 0 ]; then
    RUN_ARGS=("${PEST_ARGS[@]}")
fi

if [ "$COVERAGE" -eq 1 ]; then
    # The clover/HTML reports are requested here rather than in phpunit.xml:
    # declaring them there would enable collection on every run, and a run
    # without a driver aborts before executing a single test.
    RUN_ARGS+=("--coverage-clover=coverage/clover.xml" "--coverage-html=coverage/html")

    if [ "$TARGETED" -eq 1 ]; then
        echo "==> running Pest with coverage (targeted run: no minimum enforced)"
        RUN_ARGS+=("--coverage")
    else
        echo "==> running Pest with coverage, minimum 100%"
        RUN_ARGS+=("--coverage" "--min=100")
    fi
else
    echo "==> running Pest"
fi

set +e
# memory_limit is raised for the run only. The coverage report is built by
# unserialising everything pcov collected in one piece, and on a large suite
# that needs more than the 512M php.ini allows: the run passes and the report
# dies with "Allowed memory size exhausted", so the 100% check never runs. The
# application's own limit is unchanged.
if [ "${PEST_ARGS[*]-}" = "--group=frontend" ]; then
    # Pest has no tests in this group, and phpunit.xml fails an empty run.
    echo "    (skipped: --group=frontend is the Node suite only)"
    STATUS=0
else
    # pcov instruments only pcov.directory, which it otherwise guesses as src/:
    # tools/module/ (docs/module-command.md) is held to 100% too, so watch the
    # whole repository except vendor/, which nobody's coverage includes.
    docker compose exec -T php php -d memory_limit=2G -d pcov.directory=/var/www -d 'pcov.exclude=~/vendor/~' vendor/bin/pest ${RUN_ARGS[@]+"${RUN_ARGS[@]}"}
    STATUS=$?
fi
set -e

# --------------------------------------------------------------------------
# 9. The dashboard's own suite (src/frontend/test, Node's built-in runner).
#
# Pure modules only -- anything that needs a browser belongs in an E2E test.
# Part of the `frontend` group and of a whole-suite run. Node 26 in a throwaway
# container, so the host's Node version does not decide what passes.
#
# `--group=frontend` runs ONLY this suite: Pest has no tests in that group.
# --------------------------------------------------------------------------
FRONTEND=0
if [ "$TARGETED" -eq 0 ]; then
    FRONTEND=1
fi
for arg in ${PEST_ARGS[@]+"${PEST_ARGS[@]}"}; do
    if [ "$arg" = "--group=frontend" ]; then
        FRONTEND=1
    fi
done

if [ "$FRONTEND" -eq 1 ]; then
    echo "==> running the dashboard's tests"

    OUT="$(mktemp)"
    set +e
    docker run --rm -v "$PWD/src/frontend":/app:ro -w /app node:26-alpine node --test 'test/*.test.js' > "$OUT" 2>&1
    FRONTEND_STATUS=$?
    set -e
    grep -E '^(# (tests|pass|fail)|not ok)|^ℹ (tests|pass|fail)|^✖' "$OUT" || true
    if [ "$FRONTEND_STATUS" -ne 0 ]; then
        cat "$OUT"
    fi
    rm -f "$OUT"

    if [ "$STATUS" -eq 0 ]; then
        STATUS=$FRONTEND_STATUS
    fi
fi

exit "$STATUS"
