# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
(an app started from it numbers its releases 1, 2, 3; docs/releasing.md).

Each release moves everything under `[Unreleased]` into its own section
(AGENTS.md, "Release flow"); `./tools/release.sh` does it, and
`tests/Unit/ReleaseTest.php` fails until the version in `VERSION` has one.

## [Unreleased]

### Added
- **Waffle Iron modules** (docs/modules.md): a feature written down so an AI agent can build it into any Waffle app. A module needs a `MODULE.md` (the feature in depth, its roles and permissions, its migrations) and a `MODULE-TEST.md` (desired outcomes and how to test them); reference code and tests are optional. It can be a directory, a `.zip` or a single `.module.md` prompt file. Modules live in their own repositories and are listed in [Waffle Iron](https://github.com/treehousetim/waffle-iron). Drop one into `modules/` and `ModulesTest` checks it against the format, including that every failure is covered by an outcome, that it holds no symlinks or non-ASCII file names, and that a directory module hashes the same everywhere (the hash rule is in the spec); a fixture module keeps the checks running when `modules/` is empty.
- **The first module, [short links](https://github.com/treehousetim/waffle-short-links)** (its own repository, listed in Waffle Iron): `yoursite.com/go/<code>` redirects with daily click counts and nothing tracked about who clicks; pause, expiry, safe generated codes, and refusal of open-redirect targets. Specified, not yet proven on a Waffle version.

- **The module command, `./tools/module.sh`** (docs/module-command.md): `search`, `info`, `use` (or `install`), `record`, `outdated`, `upgrade`, `verify` and `doctor` for modules from Waffle Iron. `use` clones the catalogue (a fixed URL, refusing a rewritten history), picks the newest reviewed, unyanked version for this app's Waffle version, fetches the pinned commit only if it is on the source repository's own branches or tags, exports it with `git archive`, refuses symlinks, odd file names, control and invisible Unicode characters and a hash that does not match, runs the format check, shows what the module adds (flagging unapproved libraries and every HTML comment), asks, stages it under `.waffle/` and starts `claude` with a fixed prompt. `record` re-checks the staged copy and shipped tests, runs `./tools/test.sh --coverage` itself, and only then writes `modules.json`. The checks are PHP in `tools/module/`, held to 100% coverage; the format rules moved there from ModulesTest so both use the same ones. `setup.sh` ends by printing how to install Waffle Iron's `iron` launcher when it is missing.

- **Setups** (docs/setups.md): the ways to build with Waffle and Waffle Site, each with what it needs and what is built today: app; app + site on one server or two; back office + site; a static site published by GitHub release (DigitalOcean App Platform out of the box); one app with several sites; API only. Public pages rendered on each request are recorded as deliberately unsupported.
- **The content model, decided, not built** (docs/content-model.md; to ship as a Waffle Iron module): the tables behind anything a site publishes: publishable columns and a publish state machine, slug history, terms for hub pages, media described on upload, form submissions, sites; and the site API they serve.
- **Change notices** (AGENTS.md §4): when records a site publishes change, the app sends the record type and slugs from a queued job, and the site rebuilds only the pages affected; never "rebuild everything".
- **Decisions for v1** (RESOLVED_QUESTIONS.md): v1 after a real project; the content model as a Waffle Iron module, with blocks for pages, Markdown for posts, revisions per publish and object storage; matching majors with Waffle Site; joining by invitation, or by OAuth for open apps; projects own their code, with the changelog as the porting guide.
- **The queue** (docs/queue.md): `QueuePort` kept in MariaDB, so a job is enqueued in the same transaction as the change it describes; jobs claimed with `SKIP LOCKED` by a new `worker` Compose service (`src/cli/worker.php`, PHP now built with `pcntl`), coalesced by unique key, retried with exponential backoff and jitter, and marked dead after their last attempt, each attempt recorded in `job_runs`. A Queue page and `/api/queue` (new permission `queue-manage`, owners and administrators) list failed jobs to retry or discard; `/cron/queue` prunes finished jobs and answers 503 when the worker has stalled, which stops the heartbeat. Deploys stop the worker before migrations and start it after php restarts. Porting: add the `worker` service, the `pcntl` extension and the crontab line; run the migration.

### Changed
- **The test suite has run.** Its first full run was green apart from 72 warnings from tests that silenced file errors with `@`, which now check first; `--coverage` is at 100%. Coverage now also instruments `tools/` (pcov watches the whole repository except `vendor/`).
- **The database port.** Production no longer publishes MariaDB at all: the app, the tests and the nightly backup all reach it inside Docker. Locally it moves from 3308 to 11020 (still loopback only, `DB_HOST_PORT` to override), out of the way of other MySQL/MariaDB servers and other projects' stacks.

## [0.1.0] - 2026-10-13

### Changed
- **The backronym:** Waffle now stands for **W**eb **A**pplication **F**oundation **F**or **L**ay **E**ngineers.

### Added
- **`tools/setup.sh`, a guided first-time setup**: checks the machine and says how to install what is missing, asks for the slug, display name and hostname (suggesting each), renames, refreshes both lock files without starting the project, offers the first commit, and prints what is next. Every answer is also a flag, and it is safe to re-run. Runs on macOS (stock bash 3.2), Linux and WSL2; refuses native Windows shells with a pointer to WSL2.
- **`tools/setup-github.sh`** does the GitHub half of a first deploy in one command: creates the `prod` environment deployable from `v*` tags only, refuses a `prod-deploy` config holding anything but deploy credentials, and pipes a new read-only Doppler token straight into the environment's `DOPPLER_TOKEN`, never printed or on a command line. Safe to re-run; `--rotate-token` replaces the token.
- **Versioning and `tools/release.sh`.** `VERSION` is the one place a version is written: semver for the framework (Packagist reads the tags), one number per release for an app (`v1`, `v2`; `0` is never released). `release.sh` checks everything first (on `main`, clean, level with `origin`, tag free, `[Unreleased]` has notes, `gh` signed in), then bumps, moves the changelog section, runs the coverage gate (red puts it all back), commits, tags, pushes atomically and publishes the GitHub release. A new project starts its own stream: `VERSION` 0 and a fresh changelog recording the framework version it came from. The deploy workflow accepts only `vN` and is skipped in the framework's own repository, which would otherwise try to deploy its own releases (docs/releasing.md, with its failure list).
- **AGENTS.md: "First session in a new project"** tells an agent to name the project with `setup.sh` (never by hand), interview the user for §1, and write the first feature's failure list before any code; and never to handle secret values.
- **`composer create-project treehousetim/waffle <dir>`** runs that setup (docs/create-project.md, with its failure list).
- **MIT licence**, and a Packagist description and keywords.
- **README: services to sign up for, and a documentation table** that replaces `docs/README.md` as the one index.

### Changed
- **Caddy replaces nginx** as the web server, from one file (`docker/caddy/Caddyfile`) for local and production. It gets and renews the Let's Encrypt certificate itself: no certbot, no renewal hook, no certificate path in a config file. The hostname lives once, in `docker-compose.prod.yml`'s `SITE_ADDRESS`. Every nginx rule carries over (security headers on every response, deferred; asset caching; dotfiles refused; 2 MB bodies) and `CaddyfileTest` replaces `NginxHeadersTest` (docs/https.md, with its failure list).
- **Launch hand-holding in `deploy.sh`:** a DNS check before anything starts (`deploy/check-dns.sh`, against a public resolver; refuses the first deploy, warns afterwards, `SKIP_DNS_CHECK=1` for proxies), `caddy validate` before anything restarts, a graceful Caddy reload, and a wait for HTTPS with a valid certificate that says where to look if it does not come up.
- **Doppler is for production only, with two configs named for who reads them:** `prod-serve` (was `prd`; the server) and `prod-deploy` (GitHub Actions). The `dev` config and `doppler.yaml` are gone: local development has no secrets, every value defaults in `docker-compose.yml`, and First run is a plain `docker compose up -d`. AGENTS.md §8 says how to bring `dev` back when a feature first needs a local key, and never to point `doppler.yaml` at a production config.
- **Cron moved to its own port.** `/cron` is served only on 8081, published on the server's loopback address alone; the public site answers it with 404. Port 80 is public, and Docker's userland proxy can make an outside connection look like it came from the bridge network the old allow list trusted. The crontab now calls `http://127.0.0.1:8081/cron/<job>`.
- **`tools/rename-project.sh` renames Waffle's own names** (`waffle`, `Waffle`, `waffle.example.com`), gives `composer.json` the project's own name, keeps lines pointing at `treehousetim/waffle`, and validates the display name and hostname before touching anything.
- **`tools/changed-groups.sh`** maps `tools/`, `deploy/`, `docker/`, the Compose files, `composer.json` and `CHANGELOG.md` to `core`, whose tests assert them.

### Security
- **Composer is defended the same way** (docs/supply-chain.md). `tools/composer.sh` is the only way Composer runs, in the php service's container with no home directory; after `require`/`update`/`remove` it refuses a lock holding anything younger than a week (`composer.lock` records release times) and puts the files back. `COMPOSER_COOLDOWN_DAYS=0` lets an urgent fix in and records it in `cooldown-exceptions.txt`. The suite fails on a young package nobody recorded, so Composer run any other way is caught before a release. `allow-plugins` lists exactly the locked plugins (the stale `php-http/discovery` permission is gone). CI builds production `vendor/` with `--no-plugins --no-scripts` and runs `composer audit` before shipping. The lock was brought inside the window: `webmozart/assert` 2.5.0→2.4.1.
- **npm is defended against hijacked releases** (docs/supply-chain.md, with its failure list). `tools/npm.sh` is the only way npm runs: in a throwaway `node:26-alpine` container with only the repository mounted (no home directory, so no keys or tokens to steal), as your user, `node_modules` in a Docker volume; install scripts off there and in `src/frontend/.npmrc`; and `--before=<7 days ago>` on anything that picks versions (`NPM_COOLDOWN_DAYS=0` for an urgent fix, loudly). CI adds `npm audit signatures` between `npm ci` and the build. The lockfile was brought inside the window: `vite` 8.3.4→8.3.2, `vue-router` 5.4.0→5.3.1, `@vitejs/plugin-vue` 6.0.10→6.0.9 and 22 transitive packages had been published in the past week. Node is no longer needed on the host, and `setup.sh` no longer runs npm at all.
- **The form-body refusal decodes the path first.** `POST /%61pi/auth/login` with a form body reached the login route, because the router decodes the path and the check did not.
- **Sign-in refuses every account status but normal.** It checked only Delight's `isSuspended()`, so a banned, locked, archived or pending account got one successful sign-in before `AuthMiddleware` ended it.


### Known issues
- The full test suite has not yet been run end to end. This release exists so Packagist can list the package; treat it as a preview.

## [0.0.1] - 2026-10-12

### Added
- **The skeleton.** Slim 4 on PHP 8.5 behind nginx, MariaDB 12.3, Phinx migrations, Delight\Auth sessions with three access levels (owner, administrator, user) and one permission grid that every route names, a Vue 3 single-page app built by Vite into `src/public`, and Doppler as the only source of secrets.
- **Sign in, sign out, who am I**, and a hello page that proves the whole path end to end.
- **Settings**, the worked example of a feature slice: a key/value table whose rows describe how to render and validate themselves.
- **The test harness**: `./tools/test.sh` (Pest 4, a 100% coverage ratchet, groups per slice, `--changed`), per-test transaction rollback, DDL tests isolated in `tests/Schema`, architecture rules in `tests/Arch`, an E2E test that writes a repeatable artifact, and the frontend's Node suite.
- **Release and deploy**: a GitHub Actions workflow that builds on the runner and rsyncs a tested tree to one server on `release: published`, and an idempotent `deploy/deploy.sh`.
