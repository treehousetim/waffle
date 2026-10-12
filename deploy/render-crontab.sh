#!/usr/bin/env bash
#
# deploy/render-crontab.sh TEMPLATE HEADER_FILE [HEARTBEAT_URL]
#
# Prints deploy/crontab with its placeholders filled in. Called by deploy.sh;
# separate so it can be run by the test suite.
#
#   __CRON_HEADER_FILE__  the owner-only file holding the cron secret header
#   __HEARTBEAT__         after the health check succeeds, a ping to an
#                         outside dead-man's switch (e.g. healthchecks.io), so
#                         the app being down is noticed by something that is
#                         not the app. Empty when no URL is set.
#
# The URL is refused unless it is a plain https address: it lands in a crontab,
# where a quote is shell and a % is a newline.
set -euo pipefail

TEMPLATE="$1"
HEADER_FILE="$2"
HEARTBEAT_URL="${3:-}"

if [ ! -f "$TEMPLATE" ]; then
    echo "error: no crontab template at $TEMPLATE" >&2
    exit 1
fi

RENDERED="$(cat "$TEMPLATE")"

if [[ "$RENDERED" != *__CRON_HEADER_FILE__* ]]; then
    echo "error: $TEMPLATE contains no __CRON_HEADER_FILE__ placeholder." >&2
    exit 1
fi

PING=""

if [ -n "$HEARTBEAT_URL" ]; then
    if [[ ! "$HEARTBEAT_URL" =~ ^https://[A-Za-z0-9./_:-]+$ ]]; then
        echo "error: HEARTBEAT_URL is not a plain https URL; refusing to put it in the crontab." >&2
        exit 1
    fi

    PING=" && curl -fsS -m 10 -o /dev/null $HEARTBEAT_URL"
fi

# bash 5.2 reads `&` in a replacement as "the text matched", so the ping's `&&`
# would come out as the placeholder twice. Off where the option exists; older
# bash never had it.
shopt -u patsub_replacement 2>/dev/null || true

RENDERED="${RENDERED//__CRON_HEADER_FILE__/$HEADER_FILE}"
RENDERED="${RENDERED//__HEARTBEAT__/$PING}"

printf '%s\n' "$RENDERED"
