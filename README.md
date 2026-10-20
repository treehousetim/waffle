# Waffle

**W**eb **A**pplication **F**oundation **F**or **L**ay **E**ngineers.

## What this is, and who it's for

This starter was written by AI (Claude, in Claude Code) under human direction, and it is meant to be built on the same way: you describe what you want, an AI coding agent writes it, and the guard rails here keep the result safe to put online.

It is for people with some engineering skill who want their own custom website: comfortable in a terminal, able to read code and judge whether an answer makes sense, but not necessarily working as developers. You bring the idea and the judgement, the agent does the typing, and this project supplies the parts that are easy to get wrong:

- **Instructions the agent follows.** [AGENTS.md](AGENTS.md) is written for AI agents as much as for people. It fixes the stack, the structure and the rules, and tells an agent how to start a new project: name it, interview you about what you are building, and plan the first feature before writing any code.
- **Tests the agent has to pass.** Every change ships with tests, coverage must stay at 100%, and architecture rules fail the build, so an agent's mistakes show up as failing tests rather than on your live site.
- **Scripts instead of procedures.** Setup, GitHub, releases and dependency updates are single commands with their checks built in, so neither you nor the agent has to follow a long procedure correctly by hand.
- **Safe defaults for going live.** Automatic HTTPS, secrets kept out of the code, new package releases held back a week in case they were hijacked, and a one-command deploy to a server you own.

**Read what the agent writes.** AI makes confident mistakes. The tests catch many of them, not all, and you are responsible for what you deploy.

**Status: early preview.** v0.1.0 was released so Packagist can list the package; the full test suite has not yet been run end to end.

A starting point for a web application on a fixed stack — PHP 8.5 + Slim 4 behind Caddy (automatic HTTPS), MariaDB 12.3, Vue 3 + Vite, Docker Compose, Doppler for secrets, one server deployed by GitHub Actions — with a test-first method built in: 100% coverage as a ratchet, per-test database rollback, architecture rules enforced by the build, test groups per feature slice, and end-to-end tests that leave a repeatable artifact.

What runs today: sign in, sign out, three access levels behind one permission grid, a hello page, and a Settings page as the worked example of a feature slice.

## Documentation

| Document | Read it when | What it covers |
| --- | --- | --- |
| [AGENTS.md](AGENTS.md) | Before writing any code | The rulebook: stack, layout, layering rules, SQL conventions, testing rules, secrets, the release flow. Written to be enough on its own, for people and AI agents alike. |
| [docs/setups.md](docs/setups.md) | Before starting a project | The ways to build with Waffle: app only, app + site (one server or two), back office + site, a static site, one app with several sites, API only; what each needs and what is built today. |
| [docs/queue.md](docs/queue.md) | Anything that must not run inside a request, or adding a job | The queue: `QueuePort`, MariaDB-backed jobs, the `worker` service, coalescing, retries, failed jobs on the Queue page, the cron check, and its failure list. |
| [docs/forms.md](docs/forms.md) | A form on the site, or the inbox | Sites and their keys, receiving a submission, the honeypot and rate limit, the inbox and retention, declaring a form, the failure list. |
| [docs/invitations.md](docs/invitations.md) | Letting someone into the app | Invitations: who may invite whom, the single-use link, accepting, and the failure list. |
| [docs/storage.md](docs/storage.md) | Keeping uploaded files | `StoragePort`, the local-disk adapter and its volume, object storage later, backups, the failure list. |
| [docs/content.md](docs/content.md) | Publishing to a site, or adding a record type | The content slice as built: record types, publishing, revisions, slugs, posts, the site API, change notices, and the failure list. |
| [docs/content-model.md](docs/content-model.md) | Designing what a site publishes | The decided design for all of it: publishable columns, slug history, terms, media, form submissions, sites; and the site API they serve. Partly built (docs/content.md). |
| [docs/create-project.md](docs/create-project.md) | Starting a project from [Waffle](https://github.com/treehousetim/waffle) | What `composer create-project` asks, what the rename touches and leaves alone, and its failure list. |
| [docs/https.md](docs/https.md) | Launching on your own hostname, or HTTPS is not coming up | How Caddy gets the certificate, the DNS check, the certificate store, and its failure list. |
| [docs/fresh-install.md](docs/fresh-install.md) | Putting it on a server for the first time | From nothing to production: Doppler configs, the droplet, the certificate, the GitHub environment, the first release. |
| [docs/adding-a-feature.md](docs/adding-a-feature.md) | Starting each new feature | The test-first walkthrough: failure list, tests, then a slice (container, routes, overrides, test group). |
| [docs/access-levels.md](docs/access-levels.md) | Adding a route, or changing who may do what | The three levels and the one table of what each may do (`Permissions::GRID`). |
| [docs/settings.md](docs/settings.md) | Adding a setting, or wanting a worked example of a slice | The settings table, the Settings page, and how to add a setting. |
| [docs/module-command.md](docs/module-command.md) | Adding a module from [Waffle Iron](https://github.com/treehousetim/waffle-iron) | `./tools/module.sh` (and `iron`): search the catalogue, install a module with every check made for you, record it once the suite is green, and verify what is installed. Its failure list. |
| [docs/modules.md](docs/modules.md) | Adding a ready-made feature, or sharing one you built | [Waffle Iron](https://github.com/treehousetim/waffle/blob/main/docs/modules.md) modules: what one must contain, how an agent installs and updates it, how it is proven. They are listed in [Waffle Iron](https://github.com/treehousetim/waffle-iron); the first is [short links](https://github.com/treehousetim/waffle-short-links). |
| [docs/supply-chain.md](docs/supply-chain.md) | Adding or updating a package | How npm and Composer are kept from hijacked releases: the week-long cooldown, no install scripts or unnamed plugins, containers, signature and vulnerability checks. |
| [docs/releasing.md](docs/releasing.md) | Cutting a release | `./tools/release.sh`, how versions work for the framework and for an app, and its failure list. |
| [CHANGELOG.md](CHANGELOG.md) | Cutting a release, or catching up | What changed in each release, and what is waiting under `[Unreleased]`. |
| [OPEN-QUESTIONS.md](OPEN-QUESTIONS.md) | The brief is silent on something | Decisions not yet made. Answered ones move to [RESOLVED_QUESTIONS.md](RESOLVED_QUESTIONS.md). |

Each feature gets its own `docs/<slice>.md` holding its design, its numbered failure list and what was built; add a row here when it lands.

## Making it yours

One command, then answer three questions:

```bash
composer create-project treehousetim/waffle acme
```

Composer copies [Waffle](https://github.com/treehousetim/waffle) into `acme/` and runs the guided setup, `tools/setup.sh`. It:

1. checks your machine for Docker, Compose, git and the Doppler CLI, and says how to install anything missing (Node is not needed: npm runs in a container);
2. asks for a **slug** (database names, the Doppler project, `/opt/<slug>` on the server), a **display name**, and the **production hostname**, suggesting each from the directory name — press enter to take a suggestion;
3. renames the project, then refreshes `composer.lock` with composer or a throwaway Docker container, without starting the project;
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
| [Doppler](https://www.doppler.com) | The only source of production secrets: `prod-serve`, read by the server, and `prod-deploy`, read by GitHub Actions. Not needed locally. | Free Developer plan (3 users at last check; see [doppler.com/pricing](https://www.doppler.com/pricing)). |
| A domain registrar | The production hostname. Any registrar works; its DNS can be hosted by the registrar or by DigitalOcean for free. | A domain a year. |

Needed, but nothing to sign up for:

- **Let's Encrypt** issues the certificate. Caddy asks for it, and renews it, on its own; there is no account to create and nothing to install.
- **Docker** runs the stack locally. Docker Desktop is free for personal use and small businesses but needs a paid subscription in larger organisations; [OrbStack](https://orbstack.dev) or [Colima](https://github.com/abiosoft/colima) work too.

Optional:

- **A heartbeat monitor**, such as [healthchecks.io](https://healthchecks.io): its URL goes in `HEARTBEAT_URL`, and it alerts you when the five-minute health check stops arriving.
- **Slack**: an incoming-webhook URL in `DEPLOY_ALERT_WEBHOOK`, posted to when a deploy fails.

[docs/fresh-install.md](docs/fresh-install.md) walks through setting each one up.

## Prerequisites

- Docker (with Docker Compose v2.24 or later)
- Doppler CLI 3.76, to deploy (not needed locally)
- `gh` (GitHub CLI), for cutting releases

## First run

```bash
# 1. Start the stack (db on 127.0.0.1:11020, php-fpm, Caddy on 8080). No secrets
#    needed: every value has a local default in docker-compose.yml.
#    If something already owns a port: HTTP_PORT=8090 DB_HOST_PORT=11021 docker compose up -d
docker compose up -d

# 2. Create the schema.
docker compose exec php vendor/bin/phinx migrate -e development

# 3. Seed one local account per level. Prints the password (override with SEED_PASSWORD).
#      owner@example.test   admin@example.test   user@example.test
docker compose exec php vendor/bin/phinx seed:run -s UserSeeder

# 4. Build the frontend into src/public. npm runs in a container (docs/supply-chain.md):
#    Node need not be installed, and no package can reach your home directory.
./tools/npm.sh ci && ./tools/npm.sh run build
```

Open <http://localhost:8080> and sign in as `owner@example.test` with the password the seeder printed (`local-dev-password` unless you set `SEED_PASSWORD`).

The seeder refuses to run unless `APP_ENV` is `development`, `local` or `testing`: it creates an owner with a known password, which is the last thing that should ever reach production.

There is no `.env` file anywhere. Locally there are no secrets to hold; in production Doppler injects them into Compose, which passes them to the container, so nothing sensitive is ever written to disk.

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
| Caddy (the app) | http://localhost:8080 | `HTTP_PORT` |
| MariaDB | 127.0.0.1:11020, for a desktop database client (the app does not use it; not published in production) | `DB_HOST_PORT` |
| Vite dev server | http://localhost:5173 | |

## Frontend dev loop

```bash
./tools/npm.sh run dev
```

The dev server runs on 5173 and proxies `/api`, `/cron` and `/webhooks` to the stack on 8080, so use 5173 while working on the UI and 8080 to check the built bundle. `./tools/npm.sh run build` empties `src/public/assets` and `src/public/index.html` and writes the hashed bundle there; both are gitignored build output.

`./tools/npm.sh` is the only way npm is run: in a throwaway container, with install scripts off, and taking only versions at least a week old, because hijacked releases are usually caught within days. [docs/supply-chain.md](docs/supply-chain.md) explains each layer.

## Adding a ready-made feature

Modules are features other people have written down for any [Waffle](https://github.com/treehousetim/waffle) app, listed in [Waffle Iron](https://github.com/treehousetim/waffle-iron):

```bash
./tools/module.sh search short           # what is there
./tools/module.sh use short-links        # check it, show you what it adds, then hand it to your agent
./tools/module.sh verify                 # are the installed ones still what the catalogue lists?
```

`use` fetches exactly the files a person reviewed, refuses anything that does not check out (hash, hidden characters, symlinks, format), and asks you before anything is built; your agent then builds it and `record` writes `modules.json` only once the whole suite is green. [docs/module-command.md](docs/module-command.md) has every check, and [Waffle Iron](https://github.com/treehousetim/waffle-iron)'s `iron` command runs the same thing from anywhere inside the app.

## Going to production

[docs/fresh-install.md](docs/fresh-install.md) walks through it from nothing: Doppler configs, the server, the certificate, the GitHub environment, and the first release.

## License

[MIT](LICENSE). A project started from [Waffle](https://github.com/treehousetim/waffle) is yours to license however you like; keep the `LICENSE` file's notice with any substantial part of treehousetim/waffle you redistribute.
