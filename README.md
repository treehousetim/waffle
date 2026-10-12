# Waffle

**W**affle **A**pplication **F**ramework **F**or **L**azy **E**ngineers.

A starting point for a web application on a fixed stack — PHP 8.5 + Slim 4 behind nginx, MariaDB 12.3, Vue 3 + Vite, Docker Compose, Doppler for secrets, one server deployed by GitHub Actions — with a test-first method built in: 100% coverage as a ratchet, per-test database rollback, architecture rules enforced by the build, test groups per feature slice, and end-to-end tests that leave a repeatable artifact.

What runs today: sign in, sign out, three access levels behind one permission grid, a hello page, and a Settings page as the worked example of a feature slice.

## Documentation

| Document | Read it when | What it covers |
| --- | --- | --- |
| [AGENTS.md](AGENTS.md) | Before writing any code | The rulebook: stack, layout, layering rules, SQL conventions, testing rules, secrets, the release flow. Written to be enough on its own, for people and AI agents alike. |
| [docs/create-project.md](docs/create-project.md) | Starting a project from [Waffle](https://github.com/treehousetim/waffle) | What `composer create-project` asks, what the rename touches and leaves alone, and its failure list. |
| [docs/fresh-install.md](docs/fresh-install.md) | Putting it on a server for the first time | From nothing to production: Doppler configs, the droplet, the certificate, the GitHub environment, the first release. |
| [docs/adding-a-feature.md](docs/adding-a-feature.md) | Starting each new feature | The test-first walkthrough: failure list, tests, then a slice (container, routes, overrides, test group). |
| [docs/access-levels.md](docs/access-levels.md) | Adding a route, or changing who may do what | The three levels and the one table of what each may do (`Permissions::GRID`). |
| [docs/settings.md](docs/settings.md) | Adding a setting, or wanting a worked example of a slice | The settings table, the Settings page, and how to add a setting. |
| [CHANGELOG.md](CHANGELOG.md) | Cutting a release, or catching up | What changed in each release, and what is waiting under `[Unreleased]`. |
| [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) | The brief is silent on something | Decisions not yet made. Answered ones move to [RESOLVED_QUESTIONS.md](RESOLVED_QUESTIONS.md). |

Each feature gets its own `docs/<slice>.md` holding its design, its numbered failure list and what was built; add a row here when it lands.

## Making it yours

One command, then answer three questions:

```bash
composer create-project treehousetim/waffle acme
```

Composer copies [Waffle](https://github.com/treehousetim/waffle) into `acme/` and runs the guided setup, `tools/setup.sh`. It:

1. checks your machine for Docker, Compose, git, npm and the Doppler CLI, and says how to install anything missing;
2. asks for a **slug** (database names, the Doppler project, `/opt/<slug>` on the server), a **display name**, and the **production hostname**, suggesting each from the directory name — press enter to take a suggestion;
3. renames the project, then refreshes both lock files with the tools on your machine or a throwaway Docker container, without starting the project;
4. offers to create the git repository and make the first commit;
5. prints what to do next.

No Composer on your machine? Download or clone the repository and run the same script:

```bash
git clone --depth 1 https://github.com/treehousetim/waffle.git acme
cd acme && rm -rf .git && ./tools/setup.sh
```

It is safe to run again: finished steps are skipped. For scripts and CI, every answer has a flag (`./tools/setup.sh --help`):

```bash
./tools/setup.sh --slug acme --name "Acme Ops" --host ops.acme.com --yes
```

**Platforms.** macOS (Intel and Apple silicon; the stock bash 3.2 is fine), Linux (any distribution with bash), and Windows inside **WSL2**. Native Windows shells — Git Bash, MSYS2, Cygwin — are not supported, and the script says so and points at WSL2. [docs/create-project.md](docs/create-project.md) has the details.

When it is done, replace the placeholder paragraph in AGENTS.md §1 with what you are building.

## Services to sign up for

| Service | What it is for | Cost |
| --- | --- | --- |
| [GitHub](https://github.com) | The repository, and GitHub Actions, which builds and deploys every release through a `prod` environment. | Free for public and private repos within the Actions allowance. |
| [DigitalOcean](https://www.digitalocean.com) | The one droplet production runs on (Ubuntu LTS; 2 GB RAM is plenty to start). | From a few dollars a month. |
| [Doppler](https://www.doppler.com) | The only source of secrets, locally and in production (`dev`, `prd`, `prod-deploy`). | Free tier covers it. |
| A domain registrar | The production hostname. Any registrar works; its DNS can be hosted by the registrar or by DigitalOcean for free. | A domain a year. |

Needed, but nothing to sign up for:

- **Let's Encrypt** issues the certificate. `certbot` registers with an email address on the server; there is no account to create.
- **Docker** runs the stack locally. Docker Desktop is free for personal use and small businesses but needs a paid subscription in larger organisations; [OrbStack](https://orbstack.dev) or [Colima](https://github.com/abiosoft/colima) work too.

Optional:

- **A heartbeat monitor**, such as [healthchecks.io](https://healthchecks.io): its URL goes in `HEARTBEAT_URL`, and it alerts you when the five-minute health check stops arriving.
- **Slack**: an incoming-webhook URL in `DEPLOY_ALERT_WEBHOOK`, posted to when a deploy fails.

[docs/fresh-install.md](docs/fresh-install.md) walks through setting each one up.

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

## License

[MIT](LICENSE). A project started from [Waffle](https://github.com/treehousetim/waffle) is yours to license however you like; keep the `LICENSE` file's notice with any substantial part of treehousetim/waffle you redistribute.


Impact-Site-Verification: 6ad71094-6286-4721-8d9e-0fa7a828f830