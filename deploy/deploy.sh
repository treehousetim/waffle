#!/usr/bin/env bash
#
# deploy/deploy.sh — bring a release that deploy/pull.sh has unpacked into
# service.
#
#   bash /opt/waffle/releases/v7/deploy/deploy.sh v7
#
# Runs on the server, as the deploy user, from the release it is installing:
# waffle-pull (deploy/pull.sh) downloads the release, checks its checksum,
# unpacks it into releases/vN and runs this file from there, so the version
# running is always the new one (docs/pull-based-deployments.md).
#
# The previous release keeps serving until this one is migrated and its
# config checked; then `current` moves, the containers are recreated from the
# new release, and if it is not healthy `current` moves back.
#
# Never edit code on the server. Fix it in the repository and release.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# 1. Arguments and directories.
#
#   /opt/waffle/releases/vN   this release (RELEASE_DIR)
#   /opt/waffle/current       a link to the live release
#   /opt/waffle/shared/       what outlives releases: deploy state, backups
# ---------------------------------------------------------------------------
TAG="${1:-}"

if [ -z "$TAG" ]; then
    echo "error: a release tag is required." >&2
    echo "usage: deploy.sh <tag>" >&2
    exit 2
fi

RELEASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ROOT="$(cd "$RELEASE_DIR/../.." && pwd)"
SLUG="${SLUG:-waffle}"
STATE_DIR="$APP_ROOT/shared/deploy"
mkdir -p "$STATE_DIR"

# Compose always runs from the release itself: `.` in the Compose files is
# resolved when a container is created, so a container made from this
# directory keeps it, and the link can move without moving anything running.
cd "$RELEASE_DIR"

PREV="$( [ -L "$APP_ROOT/current" ] && basename "$(readlink "$APP_ROOT/current")" || echo "" )"
DOCKER_HASH_FILE="$STATE_DIR/docker.sha"

# Every compose call is wrapped: `up` and `build` need the values to
# interpolate, and `ps`/`exec` would otherwise warn per variable.
COMPOSE=(doppler run -- docker compose -f docker-compose.yml -f docker-compose.prod.yml)

# The link moves by a rename, which is atomic: a request sees the old release
# or the new one, never neither.
switch_to() {
    ln -sfn "releases/$1" "$APP_ROOT/current.next"
    mv -fT "$APP_ROOT/current.next" "$APP_ROOT/current"
}

# Start the previous release's queue worker again, when this one stops before
# the switch (the worker is stopped for the migrations).
resume_previous_worker() {
    [ -n "$PREV" ] || return 0
    (cd "$APP_ROOT/releases/$PREV" && "${COMPOSE[@]}" up -d --no-deps worker) || true
}

# After the switch, a release that is not healthy is switched back: the
# containers are recreated from the previous release and this one is left on
# disk for `waffle-pull status` and the journal.
switch_back() {
    echo "error: $1" >&2
    if [ -z "$PREV" ]; then
        echo "       there is no previous release to switch back to." >&2
        exit 1
    fi
    echo "==> switching back to ${PREV}" >&2
    switch_to "$PREV"
    (cd "$APP_ROOT/releases/$PREV" && "${COMPOSE[@]}" up -d --remove-orphans) || true
    exit 1
}

echo "==> deploying ${TAG} from ${RELEASE_DIR}"
echo "    live now: ${PREV:-nothing}"

# ---------------------------------------------------------------------------
# 2. The release is complete. pull.sh checked the essentials; this is the
#    whole list, because there is no composer and no Node on this server to
#    make up for anything missing.
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
    if [ ! -e "$RELEASE_DIR/$required" ]; then
        echo "error: missing ${required}" >&2
        MISSING=1
    fi
done

if [ ! -d "$RELEASE_DIR/src/public/assets" ]; then
    echo "error: missing src/public/assets — the frontend was not built." >&2
    MISSING=1
fi

if [ "$MISSING" -ne 0 ]; then
    echo "error: this release is not complete; refusing to deploy it." >&2
    exit 1
fi
echo "    complete"

# The installed poller is a copy of deploy/pull.sh, put there by hand. Say
# when this release carries a newer one, rather than let them drift silently.
if [ -f /usr/local/sbin/waffle-pull ] && ! cmp -s /usr/local/sbin/waffle-pull "$RELEASE_DIR/deploy/pull.sh"; then
    echo "warning: /usr/local/sbin/waffle-pull differs from this release's deploy/pull.sh;" >&2
    echo "         install it: sudo install -m 755 ${RELEASE_DIR}/deploy/pull.sh /usr/local/sbin/waffle-pull" >&2
fi

# ---------------------------------------------------------------------------
# 3. Doppler.
#
# Secrets are injected, never written to disk. Every compose call runs under
# `doppler run --`, which puts the `prod` config in Compose's
# environment; docker-compose.yml passes the keys it names into the
# containers, and nothing else. The server's token is scoped to /opt/waffle,
# which covers every release under it:
#   doppler configure set token <token> --scope /opt/waffle
#
# Rotating a secret is: change it in Doppler, deploy again.
# ---------------------------------------------------------------------------
echo "==> checking Doppler"

if ! command -v doppler >/dev/null 2>&1; then
    echo "error: the Doppler CLI is not installed on this host." >&2
    echo "       see https://docs.doppler.com/docs/install-cli" >&2
    exit 1
fi

if ! doppler secrets --only-names >/dev/null 2>&1; then
    echo "error: Doppler is installed but cannot read secrets from ${RELEASE_DIR}." >&2
    echo "       check: doppler configure get token --scope ${APP_ROOT}" >&2
    exit 1
fi
echo "    reachable"

# The cron secret, straight from Doppler, never by sourcing a file: `. file`
# would execute any value containing $(...). It goes into a file only this
# user can read, which the crontab and the health check below name with
# `curl -H @file`; on a command line it would be readable through ps.
CRON_SECRET="$(doppler secrets get CRON_SECRET --plain 2>/dev/null || true)"

if [ -z "${CRON_SECRET:-}" ]; then
    echo "error: CRON_SECRET is not set in Doppler; the health check and the crontab need it." >&2
    exit 1
fi

CRON_HEADER_FILE="$STATE_DIR/cron-header"
(
    umask 077
    printf 'X-Cron-Secret: %s\n' "$CRON_SECRET" > "$CRON_HEADER_FILE.tmp"
)
mv -f "$CRON_HEADER_FILE.tmp" "$CRON_HEADER_FILE"
unset CRON_SECRET

# ---------------------------------------------------------------------------
# 3b. DNS, before anything starts (docs/https.md).
#
# Caddy can only get a certificate for a hostname whose DNS leads here. On the
# first deploy a wrong record is a refusal; afterwards a warning, because the
# site is already live. SKIP_DNS_CHECK=1 skips it behind a proxy such as
# Cloudflare, whose addresses are not ours.
# ---------------------------------------------------------------------------
SITE_HOST="$(sed -n 's/^ *SITE_ADDRESS: *//p' "$RELEASE_DIR/docker-compose.prod.yml" | tr -d '"' | head -n 1)"

if [ -z "$SITE_HOST" ]; then
    echo "error: no SITE_ADDRESS in docker-compose.prod.yml; Caddy would not know its hostname." >&2
    exit 1
fi

echo "==> checking DNS for ${SITE_HOST}"
if [ "${SKIP_DNS_CHECK:-}" = "1" ]; then
    echo "    skipped (SKIP_DNS_CHECK=1)"
elif ! bash "$RELEASE_DIR/deploy/check-dns.sh" "$SITE_HOST"; then
    if [ -z "$PREV" ]; then
        echo "error: refusing the first deploy until ${SITE_HOST} points here; Caddy cannot get a certificate otherwise." >&2
        echo "       behind a proxy such as Cloudflare? Deploy with SKIP_DNS_CHECK=1." >&2
        exit 1
    fi
    echo "warning: carrying on, because ${PREV} is already live; fix DNS before the certificate next renews." >&2
fi

# ---------------------------------------------------------------------------
# 3b'. The server's front Caddy (docs/edge.md) owns ports 80 and 443 and
# reaches this app's Caddy over the `edge` network. Without it, Compose
# cannot start Caddy at all, and nobody could reach the app.
# ---------------------------------------------------------------------------
if ! docker network inspect edge >/dev/null 2>&1; then
    echo "error: there is no edge network, so the server's front Caddy is not set up; set it up first (docs/edge.md)." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# 3c. This release's Caddyfile loads. A throwaway container with this
#     release's mounts answers, and the live Caddy is not touched.
# ---------------------------------------------------------------------------
echo "==> validating docker/caddy/Caddyfile"
if ! "${COMPOSE[@]}" run --rm --no-deps -T caddy \
        caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>"$STATE_DIR/caddy-validate.log"; then
    cat "$STATE_DIR/caddy-validate.log" >&2
    echo "error: Caddy cannot load this release's docker/caddy/Caddyfile; ${PREV:-nothing} is still serving." >&2
    exit 1
fi
echo "    valid"

# ---------------------------------------------------------------------------
# 4. Rebuild images only when docker/ changed: a content hash of it, recorded
#    at the end of each good deploy. The Compose project is named for the slug,
#    so the images, like the volumes, are shared by every release.
# ---------------------------------------------------------------------------
docker_hash() {
    find "$RELEASE_DIR/docker" -type f -print0 \
        | sort -z \
        | xargs -0 sha256sum \
        | sed "s#${RELEASE_DIR}/##" \
        | sha256sum \
        | cut -d' ' -f1
}

DOCKER_HASH="$(docker_hash)"
PREV_DOCKER_HASH="$( [ -f "$DOCKER_HASH_FILE" ] && cat "$DOCKER_HASH_FILE" || echo "" )"

if [ "$DOCKER_HASH" != "$PREV_DOCKER_HASH" ]; then
    echo "==> docker/ changed since ${PREV:-nothing}; building images"
    "${COMPOSE[@]}" build
else
    echo "==> docker/ unchanged; reusing the images"
fi

# ---------------------------------------------------------------------------
# 5. The database, up and healthy. Its data is the named volume db_data
#    (docker-compose.prod.yml), outside every release.
# ---------------------------------------------------------------------------
echo "==> waiting for MariaDB"
"${COMPOSE[@]}" up -d --no-deps db

DB_WAIT_SECONDS=120
DB_CID="$("${COMPOSE[@]}" ps -q db || true)"
[ -n "$DB_CID" ] || { echo "error: the db container is not present." >&2; exit 1; }

waited=0
while :; do
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
              "$DB_CID" 2>/dev/null || echo unknown)"
    [ "$health" = "healthy" ] && break

    if [ "$waited" -ge "$DB_WAIT_SECONDS" ]; then
        echo "error: MariaDB did not become healthy within ${DB_WAIT_SECONDS}s (last: ${health})." >&2
        echo "       logs: docker compose logs db" >&2
        exit 1
    fi
    sleep 2
    waited=$((waited + 2))
done
echo "    healthy after ${waited}s"

# ---------------------------------------------------------------------------
# 6. Migrations, from this release, while the previous one still serves.
#
# The queue's worker stops first and finishes the job in hand (docs/queue.md,
# 12), so no job runs while the schema changes. A one-off container from this
# release runs phinx with the environment from `doppler run`. For those
# seconds the previous release serves against the new schema, which is why
# migrations only add, never rename or drop (AGENTS.md §6). A failed migration
# stops here, with the previous release still live.
# ---------------------------------------------------------------------------
echo "==> stopping the queue worker"
"${COMPOSE[@]}" stop worker >/dev/null 2>&1 || true

echo "==> running migrations from ${TAG}"
if ! "${COMPOSE[@]}" run --rm --no-deps -T php vendor/bin/phinx migrate -e production; then
    echo "error: the migrations failed; ${PREV:-nothing} is still serving." >&2
    resume_previous_worker
    exit 1
fi

# ---------------------------------------------------------------------------
# 7. Switch. `current` moves, and php, the worker and Caddy are recreated from
#    this release (a new process also means an empty OPcache). The database is
#    not touched.
# ---------------------------------------------------------------------------
echo "==> switching current to ${TAG}"
switch_to "$TAG"
"${COMPOSE[@]}" up -d --remove-orphans

PHP_WAIT_SECONDS=120
waited=0
echo "==> waiting for php-fpm"
while ! "${COMPOSE[@]}" exec -T php grep -q php-fpm /proc/1/cmdline >/dev/null 2>&1; do
    if [ "$waited" -ge "$PHP_WAIT_SECONDS" ]; then
        switch_back "php-fpm did not come up within ${PHP_WAIT_SECONDS}s."
    fi
    sleep 3
    waited=$((waited + 3))
done
echo "    up after ${waited}s"

# ---------------------------------------------------------------------------
# 8. Healthy, or switched back.
#
# /cron/health on the loopback-only port reaches the database and names the
# newest migration it holds. It must say success, the database ok, and this
# release's newest migration, so an error page served with a 200, or a
# release whose migrations did not run, fails.
# ---------------------------------------------------------------------------
NEWEST_MIGRATION="$(find "$RELEASE_DIR/src/app/phinx/migrations" -name '*.php' -printf '%f\n' \
    | sed -n 's/^\([0-9]\{14\}\)_.*/\1/p' | sort | tail -n 1)"

echo "==> checking health (newest migration ${NEWEST_MIGRATION})"
HEALTH_WAIT_SECONDS=60
waited=0
until curl -sf -m 10 -H @"$CRON_HEADER_FILE" http://127.0.0.1:8081/cron/health 2>/dev/null \
        | jq -e --arg migration "$NEWEST_MIGRATION" \
            '.success == true and .data.database == "ok" and .data.migration == $migration' >/dev/null 2>&1; do
    if [ "$waited" -ge "$HEALTH_WAIT_SECONDS" ]; then
        switch_back "${TAG} is not healthy after ${HEALTH_WAIT_SECONDS}s (/cron/health)."
    fi
    sleep 3
    waited=$((waited + 3))
done
echo "    healthy"

# HTTPS with a valid certificate for the hostname, checked against 127.0.0.1
# with the real name, which leaves out the cloud firewall and DNS caching. The
# first deploy waits longer: that is when Caddy gets the certificate.
echo "==> checking https://${SITE_HOST}"
HTTPS_WAIT_SECONDS=60
[ -z "$PREV" ] && HTTPS_WAIT_SECONDS=180
waited=0

while ! curl -s -o /dev/null --max-time 10 --resolve "${SITE_HOST}:443:127.0.0.1" "https://${SITE_HOST}/" 2>/dev/null; do
    if [ "$waited" -ge "$HTTPS_WAIT_SECONDS" ]; then
        echo "       The usual causes: DNS (bash deploy/check-dns.sh ${SITE_HOST}); ports 80 and 443 closed" >&2
        echo "       to the world; Let's Encrypt's rate limit; the front Caddy not naming ${SITE_HOST}" >&2
        echo "       (docs/edge.md). The front Caddy says which: cd /opt/edge && docker compose logs --tail=80 caddy" >&2
        switch_back "https://${SITE_HOST} is not serving a valid certificate after ${HTTPS_WAIT_SECONDS}s."
    fi
    sleep 5
    waited=$((waited + 5))
done
echo "    serving"

# ---------------------------------------------------------------------------
# 9. The crontab, from this release. deploy/crontab is a template; the
#    header file above is the only place the secret is on disk.
# ---------------------------------------------------------------------------
echo "==> installing crontab"

# Optional: an outside dead-man's switch (e.g. healthchecks.io) pinged after
# every successful health check.
HEARTBEAT_URL="$(doppler secrets get HEARTBEAT_URL --plain 2>/dev/null || true)"

# A heartbeat URL it will not use is a warning, never a failed deploy this late.
if ! CRON_RENDERED="$(bash "$RELEASE_DIR/deploy/render-crontab.sh" "$RELEASE_DIR/deploy/crontab" "$CRON_HEADER_FILE" "$HEARTBEAT_URL")"; then
    echo "warning: installing the crontab without the heartbeat ping" >&2
    CRON_RENDERED="$(bash "$RELEASE_DIR/deploy/render-crontab.sh" "$RELEASE_DIR/deploy/crontab" "$CRON_HEADER_FILE")"
fi

# The deploy user's crontab is shared with the site beside this app, so
# only this app's own block is replaced.
CRON_OTHERS="$(crontab -l 2>/dev/null | sed "/^# BEGIN ${SLUG}\$/,/^# END ${SLUG}\$/d" || true)"
{
    [ -z "$CRON_OTHERS" ] || printf '%s\n' "$CRON_OTHERS"
    printf '# BEGIN %s\n%s\n# END %s\n' "$SLUG" "$CRON_RENDERED" "$SLUG"
} | crontab -
echo "    $(crontab -l | sed -n "/^# BEGIN ${SLUG}\$/,/^# END ${SLUG}\$/p" | grep -c '^[^#]*curl') cron job(s) installed"

# ---------------------------------------------------------------------------
# 10. Record what was live before, and the docker/ hash. Written LAST, so a
#     failed deploy leaves the previous state in place.
# ---------------------------------------------------------------------------
[ -n "$PREV" ] && [ "$PREV" != "$TAG" ] && printf '%s\n' "$PREV" > "$STATE_DIR/previous-tag"
printf '%s\n' "$DOCKER_HASH" > "$DOCKER_HASH_FILE"

echo "==> deployed ${TAG}"
[ -n "$PREV" ] && echo "    back to ${PREV}, if it comes to that: waffle-pull deploy ${PREV}"
exit 0
