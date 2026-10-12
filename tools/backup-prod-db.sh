#!/usr/bin/env bash
#
# tools/backup-prod-db.sh — dump the production database to a gzipped file.
#
#   ./tools/backup-prod-db.sh              # writes to ./backups
#   ./tools/backup-prod-db.sh /mnt/backups # writes there instead
#
# Run this ON THE SERVER, from /opt/waffle: it talks to the `db` service of the
# production Compose stack. Credentials are injected by Doppler; deploy.sh
# regenerates from Doppler on every deploy.
#
# The last 14 dumps in the output directory are kept; older ones are deleted.
# Each new dump is then handed to tools/offsite-backup.sh, which copies it to
# the off-site bucket when Doppler names one (docs/fleet-safety.md, F26-F35).
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

OUT_DIR="${1:-$ROOT/backups}"
KEEP=14

COMPOSE=(docker compose -f docker-compose.yml -f docker-compose.prod.yml)

# --------------------------------------------------------------------------
# Credentials. Dump as root so routines, triggers and every table are included
# regardless of what the app user has been granted.
# --------------------------------------------------------------------------
# Credentials come from Doppler, injected — there is no .env on the server.
# Run this the same way everything else runs:
#     doppler run -- ./tools/backup-prod-db.sh
if [ -z "${DB_USER:-}" ] || [ -z "${DB_ROOT_PASSWORD:-}" ]; then
    echo "error: DB_USER/DB_ROOT_PASSWORD are not in the environment." >&2
    echo "       run this under Doppler: doppler run -- ./tools/backup-prod-db.sh" >&2
    exit 1
fi

DB_NAME="${DB_NAME:-waffle}"

mkdir -p "$OUT_DIR"

STAMP="$(date -u +%Y%m%d-%H%M%S)"
TARGET="$OUT_DIR/${DB_NAME}-${STAMP}.sql.gz"
TMP="${TARGET}.partial"

echo "==> dumping \`${DB_NAME}\` from the production stack"

# --single-transaction : consistent snapshot without locking InnoDB tables
# --routines/--triggers: schema objects mysqldump omits by default
# --quick              : stream rows instead of buffering the whole table
#
# Redirect only stdout into gzip so a client error message still reaches the
# terminal, and write to a .partial file so a failed dump is never mistaken for
# a good backup by the retention pass below.
#
# The root password reaches mariadb-dump as MYSQL_PWD, taken from the db
# container's own MARIADB_ROOT_PASSWORD inside it -- never as -p on a command
# line, where the host's `ps` would show it for the length of the dump.
# The database name rides in as $0.
if ! "${COMPOSE[@]}" exec -T db \
        sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump -uroot \
            --single-transaction --routines --triggers --quick \
            --default-character-set=utf8mb4 "$0"' "${DB_NAME}" \
        | gzip -9 >"$TMP"; then
    rm -f "$TMP"
    echo "error: dump failed; no backup written." >&2
    exit 1
fi

if [ ! -s "$TMP" ]; then
    rm -f "$TMP"
    echo "error: dump produced an empty file; no backup written." >&2
    exit 1
fi

mv "$TMP" "$TARGET"
chmod 600 "$TARGET"

# --------------------------------------------------------------------------
# Retention: keep the newest $KEEP by filename. The timestamp is zero-padded
# and UTC, so lexical order is chronological order.
# --------------------------------------------------------------------------
PRUNED=0
while IFS= read -r old; do
    [ -n "$old" ] || continue
    rm -f -- "$old"
    PRUNED=$((PRUNED + 1))
done < <(find "$OUT_DIR" -maxdepth 1 -type f -name "${DB_NAME}-*.sql.gz" \
         | sort -r \
         | tail -n "+$((KEEP + 1))")

if [ "$PRUNED" -gt 0 ]; then
    echo "==> pruned ${PRUNED} backup(s) older than the newest ${KEEP}"
fi

echo "$TARGET"
