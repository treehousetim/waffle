#!/usr/bin/env bash
#
# tools/npm.sh — the only way npm is run (docs/supply-chain.md).
#
#   ./tools/npm.sh ci                 # install exactly the lockfile
#   ./tools/npm.sh run build          # build into src/public
#   ./tools/npm.sh run dev            # dev server on http://localhost:5173
#   ./tools/npm.sh update             # newer versions, a week old or more
#   ./tools/npm.sh install <pkg>      # a new dependency (needs approval, AGENTS.md §2)
#
# Every npm command runs in a throwaway node:26-alpine container, as you, with
# only the repository mounted: a package's code never sees your home directory,
# so no SSH keys, tokens or browser data. node_modules lives in a Docker
# volume, never on your disk. Commands that resolve versions get --before=<N
# days ago> (NPM_COOLDOWN_DAYS, default 7), because a hijacked release is
# usually caught and pulled within days. Install scripts are off.
#
# Runs on macOS, Linux and WSL2; Node need not be installed. The numbers below
# cite docs/supply-chain.md's failure list.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DOCKER="${NPM_DOCKER:-docker}"
IMAGE="node:26-alpine"

die()  { printf 'error: %s\n' "$1" >&2; exit "${2:-1}"; }
warn() { printf 'warning: %s\n' "$1" >&2; }

case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) die "native Windows shells are not supported; run this inside WSL2 (wsl --install)." ;;   # (12)
esac

if [ "$#" -eq 0 ] || [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    sed -n '3,9p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit 0
fi

# (11) A number of days, or nothing at all: never a silent "no window".
COOLDOWN="${NPM_COOLDOWN_DAYS-7}"
[[ "$COOLDOWN" =~ ^[0-9]+$ ]] || die "NPM_COOLDOWN_DAYS must be a whole number of days (0 turns the cooldown off); got '${COOLDOWN}'." 2

command -v "$DOCKER" >/dev/null 2>&1 || die "Docker is needed: npm runs in a container (docs/supply-chain.md)."

# The date N days before today, with BSD (macOS) or GNU date.
days_before() {
    local today="$1" days="$2"
    if date --version >/dev/null 2>&1; then
        date -u -d "${today} -${days} days" +%Y-%m-%d
    else
        date -u -j -v-"${days}"d -f %Y-%m-%d "$today" +%Y-%m-%d
    fi
}

ARGS=("$@")

# (1) Commands that pick versions get the cutoff; the rest resolve nothing.
case "$1" in
    install|i|in|add|update|up|upgrade)
        if [ "$COOLDOWN" -eq 0 ]; then
            warn "the cooldown is OFF (NPM_COOLDOWN_DAYS=0): versions published minutes ago can be installed. Only for a fix you cannot wait for." # (5)
        else
            CUTOFF="$(days_before "${NPM_TODAY:-$(date -u +%Y-%m-%d)}" "$COOLDOWN")"
            ARGS+=("--before=${CUTOFF}")
        fi ;;
esac

# The slug names the node_modules volume, so projects never share one.
SLUG="$(sed -n 's/.*"name": "[^"\/]*\/\([^"]*\)".*/\1/p' composer.json 2>/dev/null | head -n 1)"
VOLUME="${SLUG:-app}_node_modules"
CACHE="${SLUG:-app}_npm_cache"
USER_ID="$(id -u):$(id -g)"

# (8) A fresh volume belongs to root; hand it to the invoking user, who runs npm.
"$DOCKER" run --rm -v "${VOLUME}:/nm" -v "${CACHE}:/cache" "$IMAGE" chown "$USER_ID" /nm /cache

RUN=(run --rm --init
     --user "$USER_ID"
     -v "${ROOT}:/app"                                       # (3) the repository, nothing else
     -v "${VOLUME}:/app/src/frontend/node_modules"           # (9)
     -v "${CACHE}:/cache"
     -w /app/src/frontend
     -e HOME=/tmp
     -e NPM_CONFIG_CACHE=/cache
     -e NPM_CONFIG_IGNORE_SCRIPTS=true                       # (2), as well as src/frontend/.npmrc
     -e NPM_CONFIG_UPDATE_NOTIFIER=false)

[ -t 0 ] && [ -t 1 ] && RUN+=(-it)

# (10) The dev server: published to this machine only, proxying to the stack.
if [ "$1" = "run" ] && [ "${2:-}" = "dev" ]; then
    RUN+=(-p 127.0.0.1:5173:5173
          --add-host host.docker.internal:host-gateway
          -e VITE_DEV_HOST=0.0.0.0
          -e "VITE_API_TARGET=http://host.docker.internal:${HTTP_PORT:-8080}")
fi

exec "$DOCKER" "${RUN[@]}" "$IMAGE" npm "${ARGS[@]}"
