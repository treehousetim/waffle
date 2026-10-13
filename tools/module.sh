#!/usr/bin/env bash
#
# tools/module.sh — modules from Waffle Iron (docs/module-command.md).
#
#   ./tools/module.sh search [words]               # modules in the catalogue
#   ./tools/module.sh info <name>                  # versions, Waffle constraint, proven-on, source
#   ./tools/module.sh use <name>[@<version>]       # check, show, stage, hand to the agent (`install` too)
#   ./tools/module.sh record <name>                # after the build: run the suite, then write modules.json
#   ./tools/module.sh outdated                     # installed modules with a newer listed version
#   ./tools/module.sh upgrade <name>               # the newest version, with its Upgrading entries
#   ./tools/module.sh verify                       # installed modules against the catalogue as it is now
#   ./tools/module.sh doctor                       # is this a Waffle app, is Docker up
#
# `iron <subcommand>` (Waffle Iron's launcher) runs this from anywhere inside
# an app. This script only dispatches, asks, starts the agent and runs the
# suite: everything that judges a file is tools/module/ (PHP), run in the php
# service's container as you, with none of your home directory. Nothing from a
# module or the catalogue is ever executed here, and there is no eval. The
# numbers cite the document's failure list.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DOCKER="${MODULE_DOCKER:-docker}"
CLAUDE="${MODULE_CLAUDE:-claude}"

die() { printf 'error: %s\n' "$1" >&2; exit "${2:-1}"; }

case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) die "native Windows shells are not supported; run this inside WSL2 (wsl --install)." ;;   # (29)
esac

usage() { sed -n '5,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

if [ "$#" -eq 0 ] || [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    usage
    exit 0
fi

# (28) An app is these four files, the same ones `iron` looks for.
is_app() { [ -f VERSION ] && [ -f AGENTS.md ] && [ -f composer.json ] && [ -x tools/module.sh ]; }
has_docker() { command -v "$DOCKER" >/dev/null 2>&1 && "$DOCKER" info >/dev/null 2>&1; }

preflight() {
    is_app || die "this is not a Waffle app: $ROOT needs VERSION, AGENTS.md, composer.json and tools/module.sh (28)."
    has_docker || die "Docker is needed and is not running: the checks run in the php container (28)."
}

# The checks, in the php service's container, as you, with HOME=/tmp. (20)
in_php() {
    "$DOCKER" compose run --rm --no-deps -T --user "$(id -u):$(id -g)" -e HOME=/tmp php php tools/module/run.php "$@"
}

# (20) Checked here too, before a name reaches any command.
NAME_RE='^[a-z][a-z0-9]*(-[a-z0-9]+)*$'
SPEC_RE='^[a-z][a-z0-9]*(-[a-z0-9]+)*(@(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*))?$'
need_name() { [[ "${1:-}" =~ $NAME_RE ]] || die "'${1:-}' is not a module name." 2; }

ask() {
    local answer=""
    printf '%s [y/N] ' "$1"
    read -r answer || true
    [ "$answer" = "y" ] || [ "$answer" = "Y" ] || [ "$answer" = "yes" ]
}

record() {
    in_php record-check "$1"
    echo "==> running the whole suite: the module is recorded only if it is green (22)"
    if ! ./tools/test.sh --coverage; then
        die "the suite is not green, so $1 is not recorded. Fix it, then: ./tools/module.sh record $1 (22)."
    fi
    in_php record-write "$1"
}

# `use` and `upgrade`: steps 1-11.
install() {
    local verb="$1" spec="$2" name
    name="${spec%@*}"
    # (30) Whatever happens next, scratch goes. Global, not local: the trap
    # runs after this function has returned.
    SCRATCH=".waffle/scratch/run-$$-$RANDOM"
    trap 'rm -rf "$ROOT/$SCRATCH"' EXIT

    if [ "$verb" = "upgrade" ]; then
        in_php prepare-upgrade "$spec" "$SCRATCH"
    else
        in_php prepare "$spec" "$SCRATCH"
    fi

    # (19) No flag skips this, and with no terminal there is no one to ask.
    if [ ! -t 0 ]; then
        die "no terminal to ask on, so nothing was staged: run this in a terminal and answer the question (19)."
    fi
    ask "Build ${name} into this app?" || die "nothing was staged."

    in_php stage "$SCRATCH"
    local prompt
    prompt="$(in_php prompt "$name")"

    if command -v "$CLAUDE" >/dev/null 2>&1; then
        echo "==> starting ${CLAUDE} to build it; it will show you what it adds before building."
        "$CLAUDE" "$prompt" || true
    else
        # (21)
        echo "==> ${CLAUDE} is not installed. Give your agent this prompt, in this directory:"
        echo
        printf '%s\n' "$prompt"
        echo
        echo "Then: ./tools/module.sh record ${name}"
        return 0
    fi

    if ask "Has the agent finished, and should the suite run now to record ${name}?"; then
        record "$name"
    else
        echo "When it is done: ./tools/module.sh record ${name}"
    fi
}

verb="$1"
shift

case "$verb" in
    doctor)
        status=0
        if is_app; then echo "ok    a Waffle app: $ROOT"; else echo "FAIL  not a Waffle app: $ROOT needs VERSION, AGENTS.md, composer.json and tools/module.sh"; status=1; fi
        if has_docker; then echo "ok    Docker is running"; else echo "FAIL  Docker is not installed or not running"; status=1; fi
        if command -v "$CLAUDE" >/dev/null 2>&1; then echo "ok    ${CLAUDE} is installed"; else echo "note  ${CLAUDE} is not installed: \`use\` will print the prompt for your own agent"; fi
        if [ "$status" -eq 0 ]; then in_php doctor || status=1; fi
        exit "$status" ;;
    search)
        preflight
        in_php search "$@" ;;
    info)
        [ "$#" -eq 1 ] || { usage >&2; exit 2; }
        need_name "$1"; preflight
        in_php info "$1" ;;
    use|install)
        [ "$#" -eq 1 ] || { usage >&2; exit 2; }
        [[ "$1" =~ $SPEC_RE ]] || die "'$1' is not a module name, or name@X.Y.Z." 2
        preflight
        install use "$1" ;;
    upgrade)
        [ "$#" -eq 1 ] || { usage >&2; exit 2; }
        need_name "$1"; preflight
        install upgrade "$1" ;;
    record)
        [ "$#" -eq 1 ] || { usage >&2; exit 2; }
        need_name "$1"; preflight
        record "$1" ;;
    outdated|verify)
        [ "$#" -eq 0 ] || { usage >&2; exit 2; }
        preflight
        in_php "$verb" ;;
    *)
        usage >&2
        exit 2 ;;
esac
