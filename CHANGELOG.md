# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each release moves everything under `[Unreleased]` into its own section
(AGENTS.md, "Release flow"); `tests/Unit/ReleaseTest.php` fails until the
version in `src/frontend/package.json` has one.

## [Unreleased]

## [0.0.1] - 2026-10-12

### Added
- **The skeleton.** Slim 4 on PHP 8.5 behind nginx, MariaDB 12.3, Phinx migrations, Delight\Auth sessions with three access levels (owner, administrator, user) and one permission grid that every route names, a Vue 3 single-page app built by Vite into `src/public`, and Doppler as the only source of secrets.
- **Sign in, sign out, who am I**, and a hello page that proves the whole path end to end.
- **Settings**, the worked example of a feature slice: a key/value table whose rows describe how to render and validate themselves.
- **The test harness**: `./tools/test.sh` (Pest 4, a 100% coverage ratchet, groups per slice, `--changed`), per-test transaction rollback, DDL tests isolated in `tests/Schema`, architecture rules in `tests/Arch`, an E2E test that writes a repeatable artifact, and the frontend's Node suite.
- **Release and deploy**: a GitHub Actions workflow that builds on the runner and rsyncs a tested tree to one server on `release: published`, and an idempotent `deploy/deploy.sh`.
