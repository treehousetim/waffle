# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each release moves everything under `[Unreleased]` into its own section
(AGENTS.md, "Release flow"); `tests/Unit/ReleaseTest.php` fails until the
version in `src/frontend/package.json` has one.

## [Unreleased]

### Added
- **`composer create-project treehousetim/waffle <dir>`** asks for a slug, display name and hostname, suggests each from the directory name, and runs `tools/rename-project.sh` (docs/create-project.md, with its failure list).
- **MIT licence**, and a Packagist description and keywords.
- **README: services to sign up for, and a documentation table** that replaces `docs/README.md` as the one index.

### Changed
- **Cron moved to its own port.** `/cron` is served only on 8081, published on the server's loopback address alone; ports 80 and 443 answer it with 404. Port 80 is public, and Docker's userland proxy can make an outside connection look like it came from the bridge network the old allow list trusted. The crontab now calls `http://127.0.0.1:8081/cron/<job>`.
- **`tools/rename-project.sh` renames Waffle's own names** (`waffle`, `Waffle`, `waffle.example.com`), gives `composer.json` the project's own name, keeps lines pointing at `treehousetim/waffle`, and validates the display name and hostname before touching anything.
- **`tools/changed-groups.sh`** maps `tools/`, `deploy/`, `docker/`, the Compose files, `composer.json` and `CHANGELOG.md` to `core`, whose tests assert them.

### Security
- **The form-body refusal decodes the path first.** `POST /%61pi/auth/login` with a form body reached the login route, because the router decodes the path and the check did not.
- **Sign-in refuses every account status but normal.** It checked only Delight's `isSuspended()`, so a banned, locked, archived or pending account got one successful sign-in before `AuthMiddleware` ended it.

## [0.0.1] - 2026-10-12

### Added
- **The skeleton.** Slim 4 on PHP 8.5 behind nginx, MariaDB 12.3, Phinx migrations, Delight\Auth sessions with three access levels (owner, administrator, user) and one permission grid that every route names, a Vue 3 single-page app built by Vite into `src/public`, and Doppler as the only source of secrets.
- **Sign in, sign out, who am I**, and a hello page that proves the whole path end to end.
- **Settings**, the worked example of a feature slice: a key/value table whose rows describe how to render and validate themselves.
- **The test harness**: `./tools/test.sh` (Pest 4, a 100% coverage ratchet, groups per slice, `--changed`), per-test transaction rollback, DDL tests isolated in `tests/Schema`, architecture rules in `tests/Arch`, an E2E test that writes a repeatable artifact, and the frontend's Node suite.
- **Release and deploy**: a GitHub Actions workflow that builds on the runner and rsyncs a tested tree to one server on `release: published`, and an idempotent `deploy/deploy.sh`.
