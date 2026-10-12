#!/usr/bin/env bash
#
# tools/release.sh — cut the next release, from version bump to GitHub release.
#
#   ./tools/release.sh               # the next release
#   ./tools/release.sh --dry-run     # say what it would do; change nothing
#   ./tools/release.sh --minor       # the framework only: --patch (default), --minor, --major
#
# VERSION holds the last released version: one number for an app (1, 2, 3; 0
# means never released), semver for the framework itself. docs/releasing.md
# explains both and holds the failure list; the numbers below cite it.
#
# Order matters: every check runs before anything changes; the gate runs
# before anything is committed; main and the tag are pushed together.
#
# Runs on macOS, Linux and WSL2.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

GH="${RELEASE_GH:-gh}"
GATE="${RELEASE_TEST_CMD:-./tools/test.sh --coverage}"

if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
    BOLD=$'\033[1m'; RED=$'\033[31m'; GREEN=$'\033[32m'; RESET=$'\033[0m'
else
    BOLD=''; RED=''; GREEN=''; RESET=''
fi

step() { printf '\n%s==> %s%s\n' "$BOLD" "$1" "$RESET"; }
ok()   { printf '  %s✓%s %s\n' "$GREEN" "$RESET" "$1"; }
die()  { printf '\n%serror:%s %s\n' "$RED" "$RESET" "$1" >&2; exit "${2:-1}"; }

usage() {
    cat <<'EOF'
Usage: ./tools/release.sh [options]

Cuts the next release: bumps VERSION, moves [Unreleased] in CHANGELOG.md into a
dated section, runs the coverage gate, commits, tags, pushes and publishes the
GitHub release. For an app, publishing the release deploys it.

Options:
  --dry-run                  say what would happen; change nothing
  --patch, --minor, --major  the framework's semver bump (default --patch);
                             an app's version is one number and takes none
  --print-next               print the next version and exit
  -h, --help                 this help
EOF
}

DRY_RUN=0; PRINT_NEXT=0; BUMP=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1; shift ;;
        --print-next) PRINT_NEXT=1; shift ;;
        --patch|--minor|--major) BUMP="${1#--}"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) usage >&2; die "unknown option: $1" 2 ;;
    esac
done

# ---------------------------------------------------------------------------
# The next version (7), from a VERSION that must be one of two shapes (8).
# ---------------------------------------------------------------------------
[ -f VERSION ] || die "no VERSION file at the repository root."
CURRENT="$(tr -d ' \t\r\n' < VERSION)"

if [[ "$CURRENT" =~ ^(0|[1-9][0-9]*)$ ]]; then
    [ -z "$BUMP" ] || die "--${BUMP} is a semver bump, and this app's version is one number: the next release is simply $((CURRENT + 1))." 2
    NEXT="$((CURRENT + 1))"
elif [[ "$CURRENT" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    MAJOR="${BASH_REMATCH[1]}"; MINOR="${BASH_REMATCH[2]}"; PATCH="${BASH_REMATCH[3]}"
    case "${BUMP:-patch}" in
        major) NEXT="$((MAJOR + 1)).0.0" ;;
        minor) NEXT="${MAJOR}.$((MINOR + 1)).0" ;;
        patch) NEXT="${MAJOR}.${MINOR}.$((PATCH + 1))" ;;
    esac
else
    die "VERSION holds '${CURRENT}', which is neither an app's release number (e.g. 7) nor the framework's semver (e.g. 0.4.2)."
fi
TAG="v${NEXT}"

if [ "$PRINT_NEXT" -eq 1 ]; then
    printf '%s\n' "$NEXT"
    exit 0
fi

# ---------------------------------------------------------------------------
# Checks. Nothing has changed yet, and nothing will if any of these fail.
# ---------------------------------------------------------------------------
step "Checking ${TAG} can be released"

git rev-parse --git-dir >/dev/null 2>&1 || die "not a git repository."

BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" = "main" ] || die "releases are cut from main, and this is ${BRANCH}. git switch main first."   # (1)

[ -z "$(git status --porcelain)" ] \
    || die "there are uncommitted changes. Commit or stash them first: a release is exactly what is on main."   # (2)

git fetch -q origin main 2>/dev/null || die "could not fetch origin/main."
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] \
    || die "main and origin/main differ. Pull or push until they match, then release."   # (3)

if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null || [ -n "$(git ls-remote --tags origin "refs/tags/${TAG}")" ]; then
    die "the tag ${TAG} already exists. Check VERSION matches the last release."   # (5)
fi

# The body of [Unreleased]: everything from its heading to the next section.
NOTES="$(awk '
    /^## \[Unreleased\]/ { inside = 1; next }
    inside && /^## \[/   { exit }
    inside               { print }
' CHANGELOG.md | sed -e '/./,$!d')"   # leading blank lines; $(...) drops trailing ones
[ -n "$(printf '%s' "$NOTES" | tr -d ' \t\n')" ] \
    || die "nothing under [Unreleased] in CHANGELOG.md. Record what changed there first."   # (4)

"$GH" auth status >/dev/null 2>&1 || die "gh is not signed in. Run: gh auth login"   # (11)

ok "on main, clean, level with origin; ${TAG} is free; [Unreleased] has notes"

if [ "$DRY_RUN" -eq 1 ]; then   # (12)
    step "Dry run: ${CURRENT} -> ${NEXT}. Would release ${TAG} with these notes:"
    printf '%s\n' "$NOTES" | sed 's/^/    /'
    printf '\n  Nothing was changed.\n'
    exit 0
fi

# ---------------------------------------------------------------------------
# Prepare: VERSION, and the changelog section moved under a dated heading (9).
# ---------------------------------------------------------------------------
step "Preparing ${TAG}"

TODAY="$(date -u +%Y-%m-%d)"
printf '%s\n' "$NEXT" > VERSION
awk -v heading="## [${NEXT}] - ${TODAY}" '
    { print }
    !done && /^## \[Unreleased\]/ { print ""; print heading; done = 1 }
' CHANGELOG.md > CHANGELOG.md.release && mv CHANGELOG.md.release CHANGELOG.md
ok "VERSION ${NEXT}; CHANGELOG.md section [${NEXT}] - ${TODAY}"

# ---------------------------------------------------------------------------
# The gate. Red puts everything back (6).
# ---------------------------------------------------------------------------
step "Running the gate: ${GATE}"

if ! sh -c "$GATE"; then
    git checkout -q -- VERSION CHANGELOG.md
    die "the tests are red, so nothing was released. VERSION and CHANGELOG.md are back as they were."
fi
ok "green"

# ---------------------------------------------------------------------------
# Commit, tag, push together (10), publish.
# ---------------------------------------------------------------------------
step "Publishing ${TAG}"

git add VERSION CHANGELOG.md
git commit -q -m "Release ${TAG}"
git tag -a "$TAG" -m "$TAG"
git push -q --atomic origin main "$TAG" \
    || die "the push was refused, so nothing reached GitHub. The commit and tag ${TAG} are here; fix the cause and run: git push --atomic origin main ${TAG}"
ok "main and ${TAG} pushed"

NOTES_FILE="$(mktemp)"
trap 'rm -f "$NOTES_FILE"' EXIT
printf '%s\n' "$NOTES" > "$NOTES_FILE"
"$GH" release create "$TAG" --title "$TAG" --notes-file "$NOTES_FILE" --verify-tag >/dev/null \
    || die "${TAG} is pushed but the GitHub release was not created. Run: gh release create ${TAG} --notes-file <notes>"
ok "GitHub release ${TAG} published"

step "Released ${TAG}"
echo "  An app deploys from here: gh run watch"
