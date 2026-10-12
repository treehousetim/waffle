# Waffle

**W**affle **A**pplication **F**ramework **F**or **L**azy **E**ngineers.

A starting point for a web application on a fixed stack — PHP 8.5 + Slim 4 behind nginx, MariaDB 12.3, Vue 3 + Vite, Docker Compose, Doppler for secrets, one server deployed by GitHub Actions — with a test-first method built in: 100% coverage as a ratchet, per-test database rollback, architecture rules enforced by the build, test groups per feature slice, and end-to-end tests that leave a repeatable artifact.

What runs today: sign in, sign out, three access levels behind one permission grid, a hello page, and a Settings page as the worked example of a feature slice.

Conventions, layering rules, testing rules and the release flow live in [AGENTS.md](AGENTS.md). Read that before writing code. The rest of the documentation starts at [docs/README.md](docs/README.md).

## Making it yours

Once, right after copying the template, before anything is started:

```bash
./tools/rename-project.sh <slug> "<Display Name>" <production-hostname>
#   e.g. ./tools/rename-project.sh acme "Acme Ops" ops.acme.com
(cd src/frontend && npm install --package-lock-only)
docker compose up -d && docker compose exec php composer update --lock
git init && git add -A && git commit -m "Start from the basic app template"
```

The slug becomes the database names, the Doppler project and `/opt/<slug>` on the server. Then replace the placeholder paragraph in AGENTS.md §1 with what you are building.

## Prerequisites

- Docker (with Docker Compose v2.24 or later)
- Node 26 LTS (for `npm run build` and `npm run dev` on the host; the test runner uses its own Node container)
- Doppler CLI 3.76, with access to the project
- `gh` (GitHub CLI), for cutting releases

## First run

```bash
# 1. Bind this checkout to the Doppler project (doppler.yaml selects config `dev`).
#    Optional locally: without Doppler every value falls back to the defaults
#    in docker-compose.yml.
doppler login && doppler setup

# 2. Start the stack (db on 127.0.0.1:3308, php-fpm, nginx on 8080).
#    If something already owns a port: HTTP_PORT=8090 DB_HOST_PORT=3309 doppler run -- docker compose up -d
doppler run -- docker compose up -d

# 3. Create the schema.
docker compose exec php vendor/bin/phinx migrate -e development

# 4. Seed one local account per level. Prints the password (override with SEED_PASSWORD).
#      owner@example.test   admin@example.test   user@example.test
docker compose exec php vendor/bin/phinx seed:run -s UserSeeder

# 5. Build the frontend into src/public.
(cd src/frontend && npm ci && npm run build)
```

Open <http://localhost:8080> and sign in as `owner@example.test` with the password the seeder printed (`local-dev-password` unless you set `SEED_PASSWORD`).

The seeder refuses to run unless `APP_ENV` is `development`, `local` or `testing`: it creates an owner with a known password, which is the last thing that should ever reach production.

Every command that needs secrets runs under `doppler run -- <cmd>`. There is no `.env` file anywhere: Doppler injects values into Compose, which passes them to the container. Nothing sensitive is ever written to disk.

## Tests

`./tools/test.sh` is the **only** supported way to run the suite. It starts Compose if needed, creates the `waffle_test` database and grants the app user, installs `pcov` into the running container on the first `--coverage` run, runs Pest inside the `php` container, and then the frontend's Node suite.

```bash
./tools/test.sh                              # everything
./tools/test.sh --coverage                   # everything, enforcing 100% coverage — the gate
./tools/test.sh --changed                    # only the groups your edits touch
./tools/test.sh --group=settings             # one slice
./tools/test.sh tests/Feature/AuthTest.php   # one file
./tools/test.sh --filter=login               # tests whose name matches
```

Tests read `.env.testing` only and never touch Doppler. End-to-end tests write their artifacts to `coverage/e2e/`.

## Ports

| Service | URL / port | Override |
|---|---|---|
| nginx (the app) | http://localhost:8080 | `HTTP_PORT` |
| MariaDB | 127.0.0.1:3308 | `DB_HOST_PORT` |
| Vite dev server | http://localhost:5173 | |

## Frontend dev loop

```bash
cd src/frontend
npm run dev
```

The dev server runs on 5173 and proxies `/api`, `/cron` and `/webhooks` to `http://localhost:8080`, so use 5173 while working on the UI and 8080 to check the built bundle. `npm run build` empties `../public/assets` and `../public/index.html` and writes the hashed bundle there; both are gitignored build output.

## Going to production

[docs/fresh-install.md](docs/fresh-install.md) walks through it from nothing: Doppler configs, the server, the certificate, the GitHub environment, and the first release.
