#!/usr/bin/env bash
#
# deploy/deploy.sh — bring a release that is ALREADY ON DISK into service.
#
#   bash deploy/deploy.sh v1.2.3
#
# Runs on the server. .github/workflows/deploy.yml has, by this point, already
# built the frontend and vendor/ on the runner and rsync'd the whole tree here,
# so this script never fetches code and the server needs no access to GitHub.
#
# WHY NOT A GIT CHECKOUT:
#   - a clone needs a deploy key on production, i.e. a source-read credential
#     sitting on the same box as every production secret
#   - the runner and the server would each fetch the tag independently, so what
#     was tested and what shipped would be two artifacts that merely ought to
#     agree
#   - composer would run here, making a deploy depend on Packagist
#
# Because the workflow rsyncs this file before invoking it, the version running
# is always the new one — no re-exec needed.
#
# Idempotent: running it twice with the same tag is a no-op beyond restarts.
# Never edit code on the server. The next deploy's rsync --delete will revert it.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# 1. Arguments and working directory.
# ---------------------------------------------------------------------------
TAG="${1:-}"

if [ -z "$TAG" ]; then
    echo "error: a release tag is required." >&2
    echo "usage: deploy.sh <tag>" >&2
    exit 2
fi

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

# Deploy state lives here rather than in git metadata. The workflow's rsync
# excludes it, so --delete never removes it.
STATE_DIR="$APP_DIR/.deploy"
mkdir -p "$STATE_DIR"

PREV_TAG_FILE="$STATE_DIR/tag"
DOCKER_HASH_FILE="$STATE_DIR/docker.sha"

PREV="$( [ -f "$PREV_TAG_FILE" ] && cat "$PREV_TAG_FILE" || echo "" )"

# Every compose call is wrapped: `up` and `build` need the values to
# interpolate, and `ps`/`restart`/`exec` would otherwise emit an interpolation
# warning per variable on a config they can no longer resolve.
COMPOSE=(doppler run -- docker compose -f docker-compose.yml -f docker-compose.prod.yml)

echo "==> deploying ${TAG} in ${APP_DIR}"
echo "    previous: ${PREV:-none recorded}"

# ---------------------------------------------------------------------------
# 2. Prove the runner actually did its half.
#
# A half-finished rsync, or someone running this by hand on a tree that was
# never built, would otherwise be discovered as a 500 in production. vendor/ and
# the built frontend are both produced on the runner and shipped; neither can be
# regenerated here, because there is no composer and no Node on this box.
# ---------------------------------------------------------------------------
echo "==> checking the release is complete"

MISSING=0
for required in \
    "vendor/autoload.php" \
    "src/public/index.php" \
    "src/public/index.html" \
    "docker-compose.yml" \
    "docker-compose.prod.yml" \
    "phinx.php"
do
    if [ ! -e "$APP_DIR/$required" ]; then
        echo "error: missing ${required}" >&2
        MISSING=1
    fi
done

if [ ! -d "$APP_DIR/src/public/assets" ]; then
    echo "error: missing src/public/assets — the frontend was not built or not shipped." >&2
    MISSING=1
fi

if [ "$MISSING" -ne 0 ]; then
    echo "error: this tree is not a complete release; refusing to deploy." >&2
    echo "       the workflow builds vendor/ and src/public on the runner and rsyncs them." >&2
    exit 1
fi
echo "    complete"

# ---------------------------------------------------------------------------
# 3. Doppler.
#
# Secrets are INJECTED, never written to disk. Every compose invocation below
# runs under `doppler run --`, which puts the `prod-serve` config in Compose's
# environment; docker-compose.yml interpolates those into the container, and
# php-fpm passes them to workers (the official image sets clear_env = no).
# `docker compose exec` inherits the container environment, so phinx sees them
# during migrations too.
#
# There is no .env on this host. Nothing sensitive is at rest, and a deploy
# that dies halfway cannot leave a stale secrets file behind.
#
# The server holds its own service token, scoped to `prod-serve`:
#   doppler configure set token <token> --scope /opt/waffle
# The GitHub runner's token is a different one, scoped to `prod-deploy`, which
# holds only the four deploy credentials — so production secrets never pass
# through CI.
#
# Rotating a secret is: change it in Doppler, redeploy.
# ---------------------------------------------------------------------------
echo "==> checking Doppler"

if ! command -v doppler >/dev/null 2>&1; then
    echo "error: the Doppler CLI is not installed on this host." >&2
    echo "       see https://docs.doppler.com/docs/install-cli" >&2
    exit 1
fi

# Fail here, loudly, rather than bringing the stack up with every secret empty
# and discovering it as a 500. `doppler secrets` needs only read access.
if ! doppler secrets --only-names >/dev/null 2>&1; then
    echo "error: Doppler is installed but cannot read secrets from ${APP_DIR}." >&2
    echo "       check: doppler configure get token --scope ${APP_DIR}" >&2
    exit 1
fi
echo "    reachable"

# ---------------------------------------------------------------------------
# 3b. DNS, before anything starts (docs/https.md).
#
# Caddy can only get a certificate for a hostname whose DNS leads here. On the
# first deploy there is no certificate yet, so a wrong record is a refusal:
# better to stop with what to fix than to start a site that cannot serve HTTPS.
# Afterwards it is a warning, because the site is already live and an
# emergency fix should not wait on DNS. SKIP_DNS_CHECK=1 skips it for a
# hostname behind a proxy such as Cloudflare, whose addresses are not ours.
# ---------------------------------------------------------------------------
SITE_HOST="$(sed -n 's/^ *SITE_ADDRESS: *//p' "$APP_DIR/docker-compose.prod.yml" | tr -d '"' | head -n 1)"

if [ -z "$SITE_HOST" ]; then
    echo "error: no SITE_ADDRESS in docker-compose.prod.yml; Caddy would not know its hostname." >&2
    exit 1
fi

echo "==> checking DNS for ${SITE_HOST}"
if [ "${SKIP_DNS_CHECK:-}" = "1" ]; then
    echo "    skipped (SKIP_DNS_CHECK=1)"
elif ! bash "$APP_DIR/deploy/check-dns.sh" "$SITE_HOST"; then
    if [ -z "$PREV" ]; then
        echo "error: refusing the first deploy until ${SITE_HOST} points here; Caddy cannot get a certificate otherwise." >&2
        echo "       behind a proxy such as Cloudflare? Re-run with SKIP_DNS_CHECK=1." >&2
        exit 1
    fi
    echo "warning: carrying on, because ${PREV} is already live; fix DNS before the certificate next renews." >&2
fi

# ---------------------------------------------------------------------------
# 3c. Validate the Caddyfile before anything restarts.
#
# A Caddyfile Caddy cannot load would otherwise take the site down at the
# restart below. `run --rm` starts a throwaway container with the same mounts
# and environment, publishes no ports, and is gone when it answers.
# ---------------------------------------------------------------------------
echo "==> validating docker/caddy/Caddyfile"
if ! "${COMPOSE[@]}" run --rm --no-deps -T caddy \
        caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>"$STATE_DIR/caddy-validate.log"; then
    cat "$STATE_DIR/caddy-validate.log" >&2
    echo "error: Caddy cannot load docker/caddy/Caddyfile; nothing was restarted." >&2
    exit 1
fi
echo "    valid"

# ---------------------------------------------------------------------------
# 4. Rebuild images only when docker/ actually changed.
#
# With no git here, the change check is a content hash of docker/ recorded at
# the end of each successful deploy.
# `compose up -d` still builds on its own if an image is missing entirely, so a
# first deploy needs no special case.
# ---------------------------------------------------------------------------
# docker/data/mysql is the MariaDB datadir, bind-mounted by the `db` service. It
# must be excluded on both counts:
#
#   - it is owned by uid 999 inside the container, while this script runs as the
#     unprivileged deploy user, so `find` would hit EACCES; with `pipefail` set
#     that aborts the whole deploy at this step, before `up -d`
#   - its contents change on every write, so the hash would differ on every run
#     and `compose build` would fire every single deploy
#
# Only the image inputs matter here: docker/php, and docker/caddy (whose
# Caddyfile is mounted, not built in, but costs nothing to include).
#
# -prune, not -not -path: a filter still descends into the directory to evaluate
# the test, so it hits the same EACCES and still exits non-zero. -prune stops
# find entering it at all. (Verified, rather than assumed — the filter form
# exits 1 against an unreadable datadir; the prune form exits 0.)
docker_hash() {
    find "$APP_DIR/docker" -path "$APP_DIR/docker/data" -prune -o -type f -print0 \
        | sort -z \
        | xargs -0 sha256sum \
        | sha256sum \
        | cut -d' ' -f1
}

DOCKER_HASH="$(docker_hash)"
PREV_DOCKER_HASH="$( [ -f "$DOCKER_HASH_FILE" ] && cat "$DOCKER_HASH_FILE" || echo "" )"

if [ -z "$PREV_DOCKER_HASH" ]; then
    echo "==> no previous docker/ hash recorded; building images"
    NEEDS_BUILD=1
elif [ "$DOCKER_HASH" != "$PREV_DOCKER_HASH" ]; then
    echo "==> docker/ changed since ${PREV:-the last deploy}; rebuilding images"
    NEEDS_BUILD=1
else
    echo "==> docker/ unchanged; reusing existing images"
    NEEDS_BUILD=0
fi

if [ "$NEEDS_BUILD" -eq 1 ]; then
    "${COMPOSE[@]}" build
fi

# ---------------------------------------------------------------------------
# 5. Bring the stack up.
#
# --remove-orphans so a service deleted from the compose files does not linger.
# ---------------------------------------------------------------------------
echo "==> starting containers"
"${COMPOSE[@]}" up -d --remove-orphans

# ---------------------------------------------------------------------------
# 6. Wait for MariaDB before migrating.
# ---------------------------------------------------------------------------
echo "==> waiting for MariaDB to become healthy"
DB_WAIT_SECONDS=120
DB_CID="$("${COMPOSE[@]}" ps -q db || true)"
if [ -z "$DB_CID" ]; then
    echo "error: the db container is not present after \`up -d\`." >&2
    exit 1
fi

waited=0
while :; do
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
              "$DB_CID" 2>/dev/null || echo unknown)"
    [ "$health" = "healthy" ] && break

    if [ "$waited" -ge "$DB_WAIT_SECONDS" ]; then
        echo "error: MariaDB did not become healthy within ${DB_WAIT_SECONDS}s (last: ${health})." >&2
        echo "       logs: ${COMPOSE[*]} logs db" >&2
        exit 1
    fi
    sleep 2
    waited=$((waited + 2))
done
echo "    MariaDB healthy after ${waited}s"

# ---------------------------------------------------------------------------
# 7. Wait for php-fpm.
#
# In production the php service's command is just `exec php-fpm` — vendor/ is
# shipped, so there is no startup composer step to race. The wait is there
# because `up -d` returns before the process is accepting connections, and
# both the migration and the restart below exec into this container.
# ---------------------------------------------------------------------------
PHP_WAIT_SECONDS=120

wait_for_php() {
    local waited=0
    echo "==> waiting for php-fpm"
    while ! "${COMPOSE[@]}" exec -T php grep -q php-fpm /proc/1/cmdline >/dev/null 2>&1; do
        if [ "$waited" -ge "$PHP_WAIT_SECONDS" ]; then
            echo "error: php-fpm did not come up within ${PHP_WAIT_SECONDS}s." >&2
            echo "       logs: ${COMPOSE[*]} logs php" >&2
            exit 1
        fi
        sleep 3
        waited=$((waited + 3))
    done
    echo "    php-fpm up after ${waited}s"
}

wait_for_php

# ---------------------------------------------------------------------------
# 8. Migrations.
#
# `docker compose exec` inherits the container's environment, and the container
# got its values from `doppler run --` at `up` time — so phinx sees the config
# with no extra plumbing and no file on disk.
#
# Migrations run BEFORE the php restart, so for a few seconds the previous
# release is serving against the new schema (opcache holds the old code until
# the restart). Write migrations the previous release tolerates: add columns and
# tables, never rename or drop them in the same release.
# ---------------------------------------------------------------------------
echo "==> running migrations"
"${COMPOSE[@]}" exec -T php vendor/bin/phinx migrate -e production

# ---------------------------------------------------------------------------
# 9. Restart. php first: opcache runs with validate_timestamps=0 in production,
#    so the new code is invisible until the process restarts.
# ---------------------------------------------------------------------------
echo "==> restarting php (clears opcache)"
"${COMPOSE[@]}" restart php

# Do not call the deploy done while php-fpm is still starting: Caddy would
# answer 502 for however long that takes.
wait_for_php

# A graceful reload picks up a changed Caddyfile without dropping a connection.
# `up -d` already recreated the container if its image or mounts changed.
echo "==> reloading Caddy"
"${COMPOSE[@]}" exec -T caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null

# ---------------------------------------------------------------------------
# 9b. Prove the site is serving HTTPS with a valid certificate.
#
# Checked from the host, against 127.0.0.1 but with the real hostname (so the
# certificate is verified for it), which leaves out the cloud firewall and DNS
# caching. The first deploy waits longer: that is when Caddy is getting the
# certificate, which takes seconds when DNS and the firewall are right.
# ---------------------------------------------------------------------------
echo "==> verifying https://${SITE_HOST} is serving"
HTTPS_WAIT_SECONDS=60
[ -z "$PREV" ] && HTTPS_WAIT_SECONDS=180
waited=0

while ! curl -s -o /dev/null --max-time 10 --resolve "${SITE_HOST}:443:127.0.0.1" "https://${SITE_HOST}/" 2>/dev/null; do
    if [ "$waited" -ge "$HTTPS_WAIT_SECONDS" ]; then
        CID="$("${COMPOSE[@]}" ps -q caddy 2>/dev/null || true)"
        STATE="$( [ -n "$CID" ] && docker inspect -f '{{.State.Status}}' "$CID" 2>/dev/null || echo "no container")"
        echo "error: https://${SITE_HOST} is not serving a valid certificate after ${HTTPS_WAIT_SECONDS}s (caddy: ${STATE})." >&2
        echo "       The usual causes, in order:" >&2
        echo "         - DNS: bash deploy/check-dns.sh ${SITE_HOST}" >&2
        echo "         - the firewall: ports 80 and 443 must be open to everyone; Let's Encrypt checks from outside" >&2
        echo "         - Let's Encrypt's rate limit, if the certificate store was wiped repeatedly" >&2
        echo "       Caddy says why: ${COMPOSE[*]} logs --tail=80 caddy" >&2
        exit 1
    fi
    sleep 5
    waited=$((waited + 5))
done
echo "    serving after ${waited}s"

# ---------------------------------------------------------------------------
# 10. Render and install the crontab.
#
# deploy/crontab is a template with a __CRON_HEADER_FILE__ placeholder. The
# secret only ever exists in Doppler and in that owner-only file, never in the
# repo and never in the crontab.
# ---------------------------------------------------------------------------
echo "==> installing crontab"

# Straight from Doppler, never by sourcing a file: `. file` would execute any
# value containing $(...). Reading it here keeps the secret in this process
# only; it reaches the header file and nowhere else.
CRON_SECRET="$(doppler secrets get CRON_SECRET --plain 2>/dev/null || true)"

if [ -z "${CRON_SECRET:-}" ]; then
    echo "error: CRON_SECRET is not set in Doppler; refusing to install a crontab" >&2
    echo "       with an unauthenticated (or placeholder) secret." >&2
    exit 1
fi

# The secret goes in a file only this user can read, and the crontab names the
# file (curl -H @file). On the curl command line it would be readable by every
# process on the server through ps. Written before the crontab that reads it,
# and replaced atomically so a cron run never reads half a file.
CRON_HEADER_FILE="$STATE_DIR/cron-header"
mkdir -p "$STATE_DIR"
(
    umask 077
    printf 'X-Cron-Secret: %s\n' "$CRON_SECRET" > "$CRON_HEADER_FILE.tmp"
)
mv -f "$CRON_HEADER_FILE.tmp" "$CRON_HEADER_FILE"

# Optional: an outside dead-man's switch (e.g. healthchecks.io) pinged after
# every successful health check, so the app being down is noticed by something
# that is not the app. Not a secret worth Doppler's protection -- it lands in
# the crontab -- but kept there with everything else this server is told.
HEARTBEAT_URL="$(doppler secrets get HEARTBEAT_URL --plain 2>/dev/null || true)"

# A heartbeat URL it will not use is a warning, never a failed deploy this late.
if ! CRON_RENDERED="$(bash "$APP_DIR/deploy/render-crontab.sh" "$APP_DIR/deploy/crontab" "$CRON_HEADER_FILE" "$HEARTBEAT_URL")"; then
    echo "warning: installing the crontab without the heartbeat ping" >&2
    CRON_RENDERED="$(bash "$APP_DIR/deploy/render-crontab.sh" "$APP_DIR/deploy/crontab" "$CRON_HEADER_FILE")"
fi

printf '%s\n' "$CRON_RENDERED" | crontab -
echo "    $(crontab -l | grep -c '^[^#]*curl') cron job(s) installed"

# ---------------------------------------------------------------------------
# 11. Record what is deployed. Written LAST, so a failed deploy leaves the
#     previous tag and docker hash in place and the next attempt re-does the
#     work rather than believing it already succeeded.
# ---------------------------------------------------------------------------
[ -n "$PREV" ] && printf '%s\n' "$PREV" > "$STATE_DIR/previous-tag"
printf '%s\n' "$TAG" > "$PREV_TAG_FILE"
printf '%s\n' "$DOCKER_HASH" > "$DOCKER_HASH_FILE"

echo "==> deployed ${TAG} successfully"
echo "    rollback: re-run the release workflow for ${PREV:-the previous tag}."
echo "    (There is no git checkout here to roll back to — the runner is the"
echo "     only thing that can build a release, and re-running it re-runs the"
echo "     whole tested pipeline.)"
