# Releasing

```bash
./tools/release.sh              # the next release
./tools/release.sh --dry-run    # what it would do, changing nothing
```

## Two kinds of version

The root `VERSION` file holds the last released version, and it is the only
place a version is written. It has one of two shapes:

- **An app: one number,** `1`, `2`, `3`. Each release is one deploy, nothing
  depends on an app's API, and so a compatibility promise like semver's has
  no audience; the number counts releases. `0` means never released. Tags are
  `v1`, `v2`, and only those deploy.
- **[treehousetim/waffle](https://github.com/treehousetim/waffle) itself: semver,** `0.1.0`, because
  `composer create-project` and Packagist read it. For a starting point,
  "breaking" means a project porting the change has work to do: **major** for
  a stack or convention change, **minor** for a new tool or capability,
  **patch** for a fix. That repository never deploys: the workflow's job is
  skipped there.

A new project starts its own stream. `tools/rename-project.sh` (run by
`tools/setup.sh`) sets `VERSION` to `0` and starts a fresh `CHANGELOG.md` whose
first line records the version it was started from, so "what has been fixed
since?" is a comparison with the framework's changelog.

The app's footer shows `v` and the `VERSION` (Vite reads the file at build
time). `package.json` carries no version: it is private, and npm insists that
a version there is semver.

## What `tools/release.sh` does

1. Works out the next version: the next number for an app; for the
   framework, the next patch, or `--minor` / `--major`.
2. Refuses unless it is on `main`, the tree is clean, `main` matches
   `origin/main`, the tag is unused, `[Unreleased]` in `CHANGELOG.md` has
   something in it, and `gh` is signed in. Nothing has changed at this point.
3. Writes `VERSION`, and moves `[Unreleased]` into a new
   `## [<version>] - <date, UTC>` section directly below it, leaving
   `[Unreleased]` empty at the top.
4. Runs the gate, `./tools/test.sh --coverage`. On red it puts `VERSION` and
   `CHANGELOG.md` back and stops: nothing is committed or tagged.
5. Commits `Release v<version>`, tags it, pushes `main` and the tag together
   (`--atomic`, so one never lands without the other), and publishes the
   GitHub release with that changelog section as its notes. For an app,
   publishing the release is what deploys it.

**CI** (`.github/workflows/ci.yml`) runs the same gate on every push to
`main` and every pull request, with no secret, no environment and a
read-only token, so a pull request from anyone can be tested safely. It is
the check, not the release: releasing still runs the gate itself, before
anything is tagged. A project started from this template keeps it; on a
private repository its runs count against the account's Actions minutes.

`tests/Unit/ReleaseTest.php` keeps the paperwork honest on every run: the
`VERSION` has its changelog section, `[Unreleased]` is on top, and nothing
else claims to be the version.

## Failure list

Each has a test in `tests/Unit/ReleaseScriptTest.php`, which runs the script
in a scratch repository with a local stand-in for `origin`, a stand-in `gh`,
and a stand-in for the test gate.

1. A release cut from a branch other than `main`.
2. Uncommitted changes, which would be left out of the tag or swept into the
   release commit.
3. `main` behind or ahead of `origin/main`, so the tag names a commit GitHub
   does not have, or the release misses someone else's work.
4. An empty `[Unreleased]`, so a release with nothing in it.
5. A tag that already exists, locally or on `origin`.
6. A red gate that still leaves a commit or a tag behind, or leaves `VERSION`
   and `CHANGELOG.md` half-edited.
7. The wrong next version: an app's `0` must become `1`, `7` become `8`; the
   framework's patch, minor and major must each bump the right part and zero
   the rest; `--minor` on an app means nothing and is refused.
8. A malformed `VERSION`, guessed at instead of refused.
9. The changelog section moved wrongly: its text altered, `[Unreleased]` not
   left empty on top, or the new section not directly below it.
10. `main` pushed without its tag, or the tag without `main`.
11. `gh` not signed in, discovered after the push.
12. `--dry-run` changing anything.
