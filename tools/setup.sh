#!/usr/bin/env bash
#
# tools/setup.sh — turn a fresh copy of Waffle into your project, guided.
#
#   ./tools/setup.sh                       # asks everything, suggests answers
#   ./tools/setup.sh --slug acme --name "Acme Ops" --host ops.acme.com --yes
#
# Runs on macOS (its stock bash 3.2 is fine), Linux, and Windows inside WSL2.
# Not native Windows shells (Git Bash, MSYS2, Cygwin): the stack needs a real
# Linux or macOS userland, so use WSL2 there.
#
# What it does, in order, each step skipped if already done:
#   1. checks the platform and the tools the project needs
#   2. asks for the slug, display name and production hostname, and renames the
#      project with tools/rename-project.sh (which validates them)
#   3. refreshes composer.lock, whose hash covers the project name, using
#      composer on this machine or a throwaway container (it never starts the
#      project's stack). The npm lockfile needs nothing: the rename rewrites the
#      name in it, and npm itself only ever runs through tools/npm.sh
#   4. offers to create the git repository and make the first commit
#   5. says what to do next
#
# `composer create-project treehousetim/waffle <dir>` runs it for you
# (tools/installer/CreateProject.php). docs/create-project.md has the details.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# ---------------------------------------------------------------------------
# Output. Colour only on a terminal, and never when NO_COLOR is set.
# ---------------------------------------------------------------------------
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    BOLD=$'\033[1m'; DIM=$'\033[2m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
    BOLD=''; DIM=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi

step()  { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
ok()    { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
warn()  { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
fail()  { printf '  %s✗%s %s\n' "$RED" "$RESET" "$1"; }
hint()  { printf '    %s%s%s\n' "$DIM" "$1" "$RESET"; }
die()   { printf '\n%serror:%s %s\n' "$RED" "$RESET" "$1" >&2; exit "${2:-1}"; }

trap 'die "setup stopped unexpectedly at line $LINENO. Nothing after that step was done; fix the cause and run ./tools/setup.sh again — finished steps are skipped."' ERR

usage() {
    cat <<'EOF'
Usage: ./tools/setup.sh [options]

Turns a fresh copy of Waffle into your project. With no options it asks for
everything it needs, suggesting an answer each time.

Options:
  --slug <slug>      lowercase letters, digits and _, e.g. acme
  --name <name>      the name people read, e.g. "Acme Ops"
  --host <hostname>  the production hostname, e.g. ops.acme.com
  --yes              accept the suggestions and confirmations without asking
  --no-git           do not create a git repository or commit
  --skip-locks       do not refresh the lock files (do it yourself later)
  --suggest <dir>    print the suggested slug, name and host for a directory
                     name, one per line, and exit
  -h, --help         this help

Works on macOS, Linux, and Windows inside WSL2.
EOF
}

# ---------------------------------------------------------------------------
# Suggestions, from the directory name. Plain POSIX tools, so they behave the
# same with BSD (macOS) and GNU (Linux) userlands.
# ---------------------------------------------------------------------------
suggest_slug() {
    local slug
    slug="$(printf '%s' "$1" | tr 'A-Z' 'a-z' | sed -e 's/[^a-z0-9][^a-z0-9]*/_/g' -e 's/^_*//' -e 's/_*$//')"
    [ -z "$slug" ] && slug="app"
    case "$slug" in [a-z]*) ;; *) slug="app_$slug" ;; esac
    [ "${#slug}" -lt 2 ] && slug="${slug}_app"
    slug="$(printf '%s' "$slug" | cut -c1-31 | sed -e 's/_*$//')"
    printf '%s\n' "$slug"
}

suggest_name() {
    printf '%s' "$1" | tr '_' ' ' | awk '{ for (i = 1; i <= NF; i++) $i = toupper(substr($i, 1, 1)) substr($i, 2) } 1'
}

suggest_host() {
    # Underscores are not allowed in hostnames.
    printf '%s.example.com\n' "$(printf '%s' "$1" | tr '_' '-')"
}

# ---------------------------------------------------------------------------
# Arguments.
# ---------------------------------------------------------------------------
SLUG=""; NAME=""; HOST=""; YES=0; NO_GIT=0; SKIP_LOCKS=0

while [ "$#" -gt 0 ]; do
    case "$1" in
        --slug) SLUG="${2:-}"; shift 2 ;;
        --name) NAME="${2:-}"; shift 2 ;;
        --host) HOST="${2:-}"; shift 2 ;;
        --yes|-y) YES=1; shift ;;
        --no-git) NO_GIT=1; shift ;;
        --skip-locks) SKIP_LOCKS=1; shift ;;
        --suggest)
            slug="$(suggest_slug "${2:-}")"
            printf '%s\n%s\n%s\n' "$slug" "$(suggest_name "$slug")" "$(suggest_host "$slug")"
            exit 0 ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" 2 ;;
    esac
done

INTERACTIVE=0
if [ -t 0 ] && [ "$YES" -eq 0 ]; then
    INTERACTIVE=1
fi

# ask "Question" "suggestion" -> the answer, or the suggestion on enter.
ask() {
    local answer
    if [ "$INTERACTIVE" -eq 0 ]; then
        printf '%s\n' "$2"
        return
    fi
    read -r -p "  $1 [$2]: " answer </dev/tty || answer=""
    printf '%s\n' "${answer:-$2}"
}

# confirm "Question" default(y|n) -> exit status 0 for yes.
confirm() {
    local answer
    if [ "$INTERACTIVE" -eq 0 ]; then
        [ "$YES" -eq 1 ] || [ "$2" = "y" ]
        return
    fi
    if [ "$2" = "y" ]; then
        read -r -p "  $1 [Y/n]: " answer </dev/tty || answer=""
        case "$answer" in [nN]*) return 1 ;; *) return 0 ;; esac
    else
        read -r -p "  $1 [y/N]: " answer </dev/tty || answer=""
        case "$answer" in [yY]*) return 0 ;; *) return 1 ;; esac
    fi
}

has() { command -v "$1" >/dev/null 2>&1; }

# Run a tool silently; show its last lines only if it fails. Tools' own
# warnings and deprecation notices otherwise bury the step they belong to.
quietly() {
    local out
    if out="$("$@" 2>&1)"; then
        return 0
    fi
    printf '%s\n' "$out" | tail -n 20 | sed 's/^/    /' >&2
    return 1
}

# lock "what" "how to do it later" command... -> refreshes, or warns and moves on.
lock() {
    local what="$1" later="$2"
    shift 2
    if quietly "$@"; then
        ok "$what"
    else
        warn "$what could not be refreshed (output above). Later: $later"
    fi
}

printf '%sWaffle setup%s — Web Application Foundation For Lay Engineers\n' "$BOLD" "$RESET"

# ---------------------------------------------------------------------------
# 1. Platform and tools.
# ---------------------------------------------------------------------------
step "Checking this machine"

PLATFORM="unknown"
case "$(uname -s)" in
    Darwin) PLATFORM="macos"; ok "macOS $(sw_vers -productVersion 2>/dev/null || true)" ;;
    Linux)
        if grep -qi microsoft /proc/version 2>/dev/null; then
            PLATFORM="wsl"; ok "Linux under WSL2"
        else
            PLATFORM="linux"; ok "Linux"
        fi ;;
    MINGW*|MSYS*|CYGWIN*)
        fail "native Windows shell ($(uname -s))"
        hint "Waffle needs macOS, Linux, or Windows inside WSL2."
        hint "Install WSL2 (wsl --install in PowerShell), open Ubuntu, and run this again there."
        exit 1 ;;
    *) warn "untested platform: $(uname -s). Carrying on; macOS, Linux and WSL2 are supported." ;;
esac

install_hint() {
    case "$PLATFORM" in
        macos) hint "install: $1" ;;
        *)     hint "install: $2" ;;
    esac
}

if has docker; then
    ok "Docker $(docker --version 2>/dev/null | sed -e 's/^Docker version //' -e 's/,.*//')"
    if docker compose version >/dev/null 2>&1; then
        ok "Docker Compose $(docker compose version --short 2>/dev/null || true)"
    else
        warn "Docker Compose v2 not found (needed to run the stack, v2.24 or later)"
        install_hint "it ships with Docker Desktop or OrbStack" "https://docs.docker.com/compose/install/linux/"
    fi
else
    warn "Docker not found (needed to run the stack)"
    install_hint "Docker Desktop, OrbStack (https://orbstack.dev) or Colima" "https://docs.docker.com/engine/install/"
fi

if has git; then ok "git $(git --version | sed 's/^git version //')"; else
    warn "git not found (needed for the first commit)"
    install_hint "xcode-select --install" "sudo apt install git   (or your distribution's package manager)"
fi

# Node is not needed here: tools/npm.sh runs npm in a container (docs/supply-chain.md).

if has doppler; then ok "Doppler CLI $(doppler --version 2>/dev/null || true)"; else
    warn "Doppler CLI not found (optional locally, required to deploy)"
    install_hint "brew install dopplerhq/cli/doppler" "https://docs.doppler.com/docs/install-cli"
fi

# ---------------------------------------------------------------------------
# 2. Names.
# ---------------------------------------------------------------------------
step "Naming the project"

if ! grep -q '"name": "treehousetim/waffle"' composer.json 2>/dev/null; then
    ok "already named ($(sed -n 's/.*"name": "\([^"]*\)".*/\1/p' composer.json | head -n 1)); skipping"
    RENAMED=0
else
    if [ "$INTERACTIVE" -eq 0 ] && [ "$YES" -eq 0 ] && { [ -z "$SLUG" ] || [ -z "$NAME" ] || [ -z "$HOST" ]; }; then
        die "no terminal to ask on. Pass --slug, --name and --host (or --yes to take the suggestions)." 2
    fi

    if [ "$INTERACTIVE" -eq 1 ]; then
        hint "The slug names the databases, the Doppler project and /opt/<slug> on the server."
        hint "The hostname is where the site will live; it can be a placeholder until you have one."
    fi

    attempts=0
    while :; do
        attempts=$((attempts + 1))
        [ -n "$SLUG" ] || SLUG="$(ask "Slug" "$(suggest_slug "$(basename "$ROOT")")")"
        [ -n "$NAME" ] || NAME="$(ask "Display name" "$(suggest_name "$SLUG")")"
        [ -n "$HOST" ] || HOST="$(ask "Production hostname" "$(suggest_host "$SLUG")")"

        if [ "$INTERACTIVE" -eq 1 ]; then
            printf '\n    slug %s%s%s · name %s%s%s · host %s%s%s\n' "$BOLD" "$SLUG" "$RESET" "$BOLD" "$NAME" "$RESET" "$BOLD" "$HOST" "$RESET"
            if ! confirm "Rename the project with these?" y; then
                SLUG=""; NAME=""; HOST=""
                continue
            fi
        fi

        # In an && / || list, so neither errexit nor the ERR trap fires on a
        # refusal (the trap ignores set +e).
        code=0
        output="$(bash "$ROOT/tools/rename-project.sh" "$SLUG" "$NAME" "$HOST" 2>&1)" || code=$?

        if [ "$code" -eq 0 ]; then
            ok "renamed: slug $SLUG, name \"$NAME\", host $HOST"
            RENAMED=1
            break
        fi

        # 2 is an answer the rename script refused; say why and ask again.
        printf '%s\n' "$output" | grep '^error' | sed 's/^error: /  /' >&2 || true
        if [ "$code" -ne 2 ] || [ "$INTERACTIVE" -eq 0 ] || [ "$attempts" -ge 3 ]; then
            printf '%s\n' "$output" >&2
            die "the project was not renamed." "$code"
        fi
        SLUG=""; NAME=""; HOST=""
    done
fi

# ---------------------------------------------------------------------------
# 3. composer.lock. Its content hash covers the project's name, so a rename
#    leaves it stale. (package-lock.json only carries the name, which the
#    rename has already rewritten.)
# ---------------------------------------------------------------------------
step "Refreshing composer.lock"

if [ "$SKIP_LOCKS" -eq 1 ]; then
    warn "skipped (--skip-locks). Later: composer update --lock"
elif [ "${RENAMED:-0}" -eq 0 ]; then
    ok "nothing renamed, nothing to refresh"
else
    COMPOSER_LATER="composer update --lock"
    if has composer; then
        lock "composer.lock (composer on this machine)" "$COMPOSER_LATER" \
            composer update --lock --no-install --no-scripts --ignore-platform-reqs
    elif has docker; then
        lock "composer.lock (throwaway composer:2 container)" "$COMPOSER_LATER" \
            docker run --rm -v "$ROOT:/app" -w /app composer:2 \
            update --lock --no-install --no-scripts --ignore-platform-reqs
    else
        warn "composer.lock not refreshed: needs composer or Docker. Later: $COMPOSER_LATER"
    fi
fi

# ---------------------------------------------------------------------------
# 4. Git.
# ---------------------------------------------------------------------------
step "Git"

if [ "$NO_GIT" -eq 1 ]; then
    ok "skipped (--no-git)"
elif ! has git; then
    warn "git is not installed; skipping"
elif [ -d .git ]; then
    origin="$(git remote get-url origin 2>/dev/null || true)"
    case "$origin" in
        *treehousetim/waffle*)
            warn "this is a clone of Waffle's own repository (origin: $origin)"
            hint "Your project needs its own history. To start one:"
            hint "  rm -rf .git && ./tools/setup.sh"
            ;;
        *) ok "already a git repository; commit when you are ready" ;;
    esac
elif confirm "Create a git repository and make the first commit?" y; then
    git init -q
    git add -A
    if git -c user.useConfigOnly=true commit -q -m "Start from treehousetim/waffle" 2>/dev/null; then
        ok "first commit made"
    else
        warn "repository created, but git has no name and email to commit with"
        hint "git config --global user.name \"Your Name\" && git config --global user.email you@example.com"
        hint "then: git commit -m \"Start from treehousetim/waffle\""
    fi
else
    ok "skipped"
fi

# ---------------------------------------------------------------------------
# 5. What next.
# ---------------------------------------------------------------------------
step "Done. Next:"
cat <<EOF
  1. Fill in section 1 of AGENTS.md with what you are building.
  2. Start the stack and open it:
       docker compose up -d
       docker compose exec php vendor/bin/phinx migrate -e development
       docker compose exec php vendor/bin/phinx seed:run -s UserSeeder
       ./tools/npm.sh ci && ./tools/npm.sh run build
     then http://localhost:8080 (README.md, "First run").
  3. Before your first deploy: sign up for the services in README.md, then
     follow docs/fresh-install.md.
EOF
