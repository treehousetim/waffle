#!/usr/bin/env bash
#
# tools/setup-github.sh — connect GitHub Actions to Doppler for deploys.
#
#   ./tools/setup-github.sh                    # repo from the git remote
#   ./tools/setup-github.sh --rotate-token     # replace an existing token
#
# Does docs/fresh-install.md steps 13-14 in one go, once Doppler's prod-deploy
# config is filled (step 12) and both CLIs are signed in:
#
#   1. checks prod-deploy holds the deploy credentials and nothing more
#   2. creates the `prod` GitHub environment, deployable from v* tags only
#   3. issues a read-only Doppler token for prod-deploy and pipes it straight
#      into the environment's DOPPLER_TOKEN secret: never printed, never on a
#      command line
#
# Safe to re-run. It replaces an existing token only with --rotate-token.
# Required reviewers stay a manual choice (Settings -> Environments -> prod).
# Runs on macOS, Linux and WSL2. The numbers below cite the failure list in
# docs/fresh-install.md's appendix.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

GH="${SETUP_GH:-gh}"
DOPPLER="${SETUP_DOPPLER:-doppler}"
CONFIG="prod-deploy"
ENVIRONMENT="prod"

# What prod-deploy may hold. Anything else is a production secret that would
# reach the CI runner (5). Doppler adds its own DOPPLER_* names to every config.
REQUIRED="DROPLET_HOST DROPLET_USER DROPLET_SSH_KEY DROPLET_SSH_KNOWN_HOSTS"
OPTIONAL="DEPLOY_ALERT_WEBHOOK"

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    BOLD=$'\033[1m'; RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; RESET=$'\033[0m'
else
    BOLD=''; RED=''; GREEN=''; YELLOW=''; RESET=''
fi

step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
warn() { printf '  %s!%s %s\n' "$YELLOW" "$RESET" "$1"; }
die()  { printf '\n%serror:%s %s\n' "$RED" "$RESET" "$1" >&2; exit "${2:-1}"; }

usage() {
    cat <<'EOF'
Usage: ./tools/setup-github.sh [options]

Creates the `prod` GitHub environment (v* tags only) and gives it a read-only
Doppler token for the prod-deploy config.

Options:
  --repo <owner/name>  the GitHub repository (default: from the git remote)
  --project <name>     the Doppler project (default: the composer package name)
  --rotate-token       replace an existing DOPPLER_TOKEN
  -h, --help           this help
EOF
}

REPO=""; PROJECT=""; ROTATE=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --repo) REPO="${2:-}"; shift 2 ;;
        --project) PROJECT="${2:-}"; shift 2 ;;
        --rotate-token) ROTATE=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" 2 ;;
    esac
done

# ---------------------------------------------------------------------------
# Tools and sign-in, before anything changes (1, 2).
# ---------------------------------------------------------------------------
step "Checking the CLIs"

command -v "$GH" >/dev/null 2>&1 \
    || die "gh (the GitHub CLI) is not installed. macOS: brew install gh · Linux: https://github.com/cli/cli#installation"
"$GH" auth status >/dev/null 2>&1 \
    || die "gh is not signed in. Run: gh auth login"
ok "gh signed in"

command -v "$DOPPLER" >/dev/null 2>&1 \
    || die "doppler (the Doppler CLI) is not installed. macOS: brew install dopplerhq/cli/doppler · Linux: https://docs.doppler.com/docs/install-cli"
"$DOPPLER" me --json >/dev/null 2>&1 \
    || die "doppler is not signed in. Run: doppler login"
ok "doppler signed in"

# ---------------------------------------------------------------------------
# Which repository and which Doppler project (3).
# ---------------------------------------------------------------------------
if [ -z "$REPO" ]; then
    REPO="$("$GH" repo view --json nameWithOwner -q .nameWithOwner 2>/dev/null || true)"
    [ -n "$REPO" ] || die "could not work out the GitHub repository from this checkout. Pass --repo owner/name."
fi

if [ -z "$PROJECT" ]; then
    # The slug: composer.json's package is <slug>/<slug> after setup.sh.
    PROJECT="$(sed -n 's/.*"name": "[^"\/]*\/\([^"]*\)".*/\1/p' composer.json | head -n 1)"
    [ -n "$PROJECT" ] || die "could not work out the Doppler project from composer.json. Pass --project."
fi
ok "repository ${REPO}, Doppler project ${PROJECT}"

# ---------------------------------------------------------------------------
# prod-deploy: exists (4), holds nothing it should not (5), lacks nothing (6).
# Names only; no value is ever read.
# ---------------------------------------------------------------------------
step "Checking Doppler's ${CONFIG} config"

"$DOPPLER" configs get "$CONFIG" --project "$PROJECT" --json >/dev/null 2>&1 \
    || die "Doppler project ${PROJECT} has no ${CONFIG} config. Create it and fill it first (docs/fresh-install.md, steps 3 and 12)."

NAMES="$("$DOPPLER" secrets --only-names --project "$PROJECT" --config "$CONFIG" --json 2>/dev/null \
         | grep -o '"[A-Z][A-Z0-9_]*"' | tr -d '"' | grep -v '^DOPPLER_' | sort -u || true)"

EXTRA=""
for name in $NAMES; do
    case " $REQUIRED $OPTIONAL " in
        *" $name "*) ;;
        *) EXTRA="$EXTRA $name" ;;
    esac
done
if [ -n "$EXTRA" ]; then
    die "${CONFIG} holds more than deploy credentials:${EXTRA}
       The CI runner reads this config, so these would reach it. Delete them from
       ${CONFIG} (they belong in prod-serve; was \"Add missing value\" clicked?), then run this again."
fi

MISSING=""
for name in $REQUIRED; do
    case " $(printf '%s' "$NAMES" | tr '\n' ' ') " in
        *" $name "*) ;;
        *) MISSING="$MISSING $name" ;;
    esac
done
if [ -n "$MISSING" ]; then
    warn "${CONFIG} is missing:${MISSING}. Deploys will fail until they are set (docs/fresh-install.md, step 12)."
else
    ok "${CONFIG} holds the deploy credentials and nothing else"
fi

# ---------------------------------------------------------------------------
# The environment: v* tags and nothing else may deploy (7), added once (8).
# ---------------------------------------------------------------------------
step "The ${ENVIRONMENT} environment"

POLICIES="$("$GH" api "repos/${REPO}/environments/${ENVIRONMENT}/deployment-branch-policies" \
             --jq '.branch_policies[] | "\(.type // "branch") \(.name)"' 2>/dev/null || true)"

OTHERS="$(printf '%s\n' "$POLICIES" | grep -v -x 'tag v\*' | grep -v '^$' || true)"
if [ -n "$OTHERS" ]; then
    die "the ${ENVIRONMENT} environment already lets these deploy, besides v* tags:
$(printf '%s\n' "$OTHERS" | sed 's/^/         /')
       Anything that can deploy can read DOPPLER_TOKEN. Remove them in
       Settings -> Environments -> ${ENVIRONMENT}, then run this again."
fi

"$GH" api -X PUT "repos/${REPO}/environments/${ENVIRONMENT}" --input - >/dev/null <<'JSON'
{"deployment_branch_policy": {"protected_branches": false, "custom_branch_policies": true}}
JSON
ok "environment ${ENVIRONMENT} exists, deployable by rule only"

if printf '%s\n' "$POLICIES" | grep -x 'tag v\*' >/dev/null; then
    ok "v* tag rule already there"
else
    "$GH" api -X POST "repos/${REPO}/environments/${ENVIRONMENT}/deployment-branch-policies" \
        -f name='v*' -f type=tag >/dev/null
    ok "v* tag rule added"
fi

# ---------------------------------------------------------------------------
# The token: read-only (11), piped (10), and not replaced unasked (9).
# ---------------------------------------------------------------------------
step "DOPPLER_TOKEN"

# Not grep -q: under pipefail its early exit can fail the pipe, which would
# read as "no token" and replace a working one.
if "$GH" secret list --env "$ENVIRONMENT" --repo "$REPO" --json name --jq '.[].name' 2>/dev/null | grep -x 'DOPPLER_TOKEN' >/dev/null \
   && [ "$ROTATE" -eq 0 ]; then
    ok "already set; left alone (--rotate-token replaces it)"
else
    TOKEN_NAME="github-actions-$(date -u +%Y%m%d%H%M%S)"
    # From Doppler straight into GitHub: the value is never in a variable, an
    # argument, or the terminal.
    "$DOPPLER" configs tokens create "$TOKEN_NAME" --project "$PROJECT" --config "$CONFIG" --access read --plain \
        | "$GH" secret set DOPPLER_TOKEN --env "$ENVIRONMENT" --repo "$REPO" >/dev/null \
        || die "the token was not stored. If Doppler created ${TOKEN_NAME}, revoke it under ${CONFIG} -> Access."
    ok "read-only token ${TOKEN_NAME} stored as the ${ENVIRONMENT} environment's DOPPLER_TOKEN"
    [ "$ROTATE" -eq 1 ] && warn "revoke the previous github-actions-* token under ${CONFIG} -> Access in Doppler"
fi

step "Done"
cat <<EOF
  GitHub Actions can now deploy ${REPO} from a v* release tag.
  Optional: require a reviewer for each deploy in Settings -> Environments -> ${ENVIRONMENT}.
EOF
