# Pull-based deploys from GitHub Releases

**Status: proposal, not built.** Today a release is pushed: GitHub Actions
rsyncs the tree to the server over SSH and runs `deploy/deploy.sh`
(docs/releasing.md). This is the other way round: the server pulls each
release itself, migrates the database, switches atomically, checks health and
rolls back on failure, and CI never has any access to the server.

The stack does not change: Docker Compose, Caddy in front (docs/https.md),
PHP-FPM and the queue worker behind it, MariaDB, and Doppler as the only
source of secrets. Waffle Site's static releases follow the same pattern
("The site", below).

## Overview

```
developer ──./tools/release.sh──▶ GitHub Release vN
                              │  CI builds and attaches <slug>-vN.tar.gz + .sha256
                              ▼
server: systemd timer (every minute) ──▶ waffle-deploy check
   ├─ guards: newer than live? approved author? assets uploaded? checksum OK?
   ├─ unpack to  /opt/<slug>/releases/vN/
   ├─ validate the release's Caddyfile; rebuild the php image if docker/ changed
   ├─ worker stopped; phinx migrate from the new release, under doppler run
   ├─ atomic switch: /opt/<slug>/current → releases/vN, containers recreated from it
   ├─ health check over loopback; HTTPS checked for the hostname
   └─ on failure: current back to the previous release, containers recreated, alert
```

**Nothing pushes to the server.** CI only publishes files to the release, with
the workflow's own `GITHUB_TOKEN`. The server needs one read-only GitHub token,
kept in Doppler like every other secret.

## What changes from today

| Today | Pull-based |
| --- | --- |
| `deploy.yml`: a `build` job, then a `ship` job that rsyncs over SSH | `build` only: it attaches the tarball and its checksum to the release. The `ship` job is gone. |
| Doppler `prod-deploy`: `DROPLET_HOST`, `DROPLET_USER`, `DROPLET_SSH_KEY`, `DROPLET_SSH_KNOWN_HOSTS`, `DEPLOY_ALERT_WEBHOOK` | **Gone.** Nothing outside connects to the server, so it holds no deploy key and no known hosts. The alert moves to the server. |
| GitHub `prod` environment holding `DOPPLER_TOKEN` | No secret at all; `tools/setup-github.sh` keeps only the `v*` tag rule. |
| One tree at `/opt/<slug>`, replaced by `rsync --delete` | `/opt/<slug>/releases/vN/`, one per release, and `current` linking to the live one. |
| A GitHub read credential is never on the server | One fine-grained token, **read-only, on this repository only**, used to fetch release files, never source. |
| `deploy.sh` run by the workflow | The same steps, run by `waffle-deploy` on the server, from the release it is installing. |

## Secrets: Doppler on the server, no env files

There is still no `.env` file anywhere, and nothing secret is written to disk.

- **The server keeps its Doppler service token for `prod-serve`**, scoped to
  `/opt/<slug>` (`doppler configure set token … --scope /opt/<slug>`), which
  covers every release under it. Every Compose call runs under
  `doppler run --`, as today, so secrets reach the containers' environment and
  nowhere else.
- **The deploy's own secrets are keys in `prod-serve` that no container
  receives**, read with `doppler secrets get --plain` the way `deploy.sh`
  reads `CRON_SECRET` today: `GITHUB_RELEASES_TOKEN` (the read-only token) and
  the alert target. A container only sees the keys its Compose `environment:`
  block names, and neither is named there.
- **Settings that are not secret** live in `/etc/waffle-deploy/<slug>.conf`:
  `REPO`, `DEPLOY_AUTHORS`, `KEEP`, the hostname to check. It holds no token.
- **Migrations run as the app's own database user**, as today. A separate
  migration user is possible later (its own keys in `prod-serve`, read only by
  the migrate step), but it is not needed to adopt this.

## Components

| Piece | Purpose |
| --- | --- |
| GitHub Release + `deploy.yml`'s `build` job | The deploy unit. CI builds the tree the way it does today (`vendor/` with `--no-dev`, the frontend in `src/public`), then attaches `<slug>-vN.tar.gz` and `<slug>-vN.tar.gz.sha256`. |
| `/usr/local/sbin/waffle-deploy` | The deploy script, with `check`, `deploy <tag>`, `rollback`, `status` and `retry`. Installed from the repository's `deploy/` by hand (Conventions). |
| `waffle-deploy@<slug>.timer` + `.service` | Runs `waffle-deploy check <slug>` every minute (`OnUnitActiveSec=1min`, `OnBootSec=2min`). One instance per project, so an app and its site on one droplet deploy independently. |
| `/etc/waffle-deploy/<slug>.conf` | The settings above; nothing secret. |
| `/opt/<slug>/releases/vN/` | One directory per release, owned by `deploy`. |
| `/opt/<slug>/current` | The link to the live release. Compose, cron and the backup script always use this path. |
| `/opt/<slug>/shared/` | What survives releases and is not a named volume: the MariaDB data directory, `.deploy/` (the deploy's state and the cron header file), `backups/`. |

The named volumes (`caddy_data`, the certificate store; `storage_data`,
uploads) already outlive every release, because the Compose project is named
for the slug, not for the folder it runs from.

## The `check` flow

1. **Find the latest release** through the GitHub API. If it is already live,
   exit quietly.
2. **Guards.** Refuse, log and alert once per tag, deploying nothing, if any
   of these fail:
   - **Newer than live:** compare version numbers (`v12` after `v9`), not
     dates, so an old tag marked "latest" again cannot roll the server back.
   - **Approved author:** the release's publisher is in `DEPLOY_AUTHORS`.
   - **Assets present:** the tarball and its `.sha256` are both uploaded. If
     not, wait without failing, and alert once if they are still missing after
     N minutes (CI attaches them a few minutes after the release is published).
   - **Checksum:** the tarball matches its `.sha256`.
   - **Complete:** the unpacked tree has `vendor/autoload.php` and
     `src/public/assets`, as `deploy.sh` requires today.
   - **Single run:** a lock file; a timer tick during a deploy is skipped.
   - **No retry loop:** a tag that failed is not tried again until a newer
     release appears or someone runs `retry`.
3. **Unpack** into `releases/vN.partial`, then rename it to `releases/vN`, with
   `--no-same-owner`, owned by `deploy`.
4. **Check the release's server config before anything stops:** `caddy validate`
   against the new Caddyfile, and the php image rebuilt only if the hash of
   `docker/` changed, as `deploy.sh` does today. Either failing stops here,
   with the old release still serving.
5. **Migrate.** Stop the queue `worker` (it finishes the job in hand, within its
   grace period), then run `phinx migrate -e production` from the new release,
   under `doppler run`. **If migrations fail, stop: the old release stays live**
   and the worker starts again on it.
6. **Switch atomically**, then recreate the containers from it:
   ```bash
   ln -sfn "releases/$tag" "$APP/current.next" && mv -fT "$APP/current.next" "$APP/current"
   doppler run -- docker compose --project-directory "$APP/current" \
     -f docker-compose.yml -f docker-compose.prod.yml up -d --force-recreate php worker caddy
   ```
   A running container keeps the directory it was started with, so restarting
   is not enough: `php`, `worker` and `caddy` are recreated, which also clears
   OPcache. `db` is not touched.
7. **Long-running jobs** are the queue `worker`, already restarted from
   `current` in step 6. Cron calls the app over `127.0.0.1:8081`, so it needs no
   restart; the crontab is rendered again from the new release, as today.
8. **Health check, over loopback:** `GET http://127.0.0.1:8081/cron/health`
   with the cron header file. Require **HTTP 200, `"success":true`,
   `"database":"ok"`, and `"migration"` equal to the newest migration in the
   new release**, so an error page, or a release whose migrations did not run,
   fails. Then the HTTPS check `deploy.sh` makes today: a valid certificate for
   the hostname.
9. **Roll back on failure:** `current` back to the previous release, the same
   containers recreated, and an alert. **Code rolls back, the schema does
   not**, which is why migrations only ever expand (AGENTS.md §6).
10. **Prune.** Keep the newest `KEEP` releases, plus whatever is live or
    previous.

## The site

Waffle Site already deploys this way on the server: `releases/vN/`, `current`
switched by a rename, Caddy serving `/srv/site/current`, and an instant switch
back if the new release is not being served. Pull-based changes only how a
release arrives:

- **Its `build` job attaches `<slug>-vN.tar.gz` (the built `dist/`) and its
  `.sha256`** to the release; no `ship` job, and no SSH.
- **Nothing is recreated:** Caddy serves through the `current` link, so the
  switch alone is the deploy. A changed Caddyfile is validated and reloaded,
  as today.
- **Scoped rebuilds** (a change notice from the app) become releases too: the
  rebuild workflow publishes `vN.dRUN` with its tarball, and `check` orders it
  after `vN`. Their publisher is the workflow (`github-actions[bot]`), allowed
  in `DEPLOY_AUTHORS` for `.d` releases only.
- **Health** is the check `deploy.sh` makes today: the hostname serves the new
  release's version over HTTPS.

## One droplet, two hostnames

With the app and the site on one server (docs/setups.md, "One server"), each
has its own timer instance, its own `/opt/<slug>`, and its own Caddy behind
the edge Caddy that holds both certificates. The edge is server config: it is
not part of either project's release (Conventions).

## Manual commands

| Command | Use |
| --- | --- |
| `waffle-deploy deploy <slug> <tag>` | Deploy a specific tag. **Not guarded**, so a deliberate redeploy or rollback to an older tag still works. |
| `waffle-deploy rollback <slug>` | `current` back to the previous release, containers recreated (code only). |
| `waffle-deploy status <slug>` | Live and previous release, lock state, last result. |
| `waffle-deploy retry <slug>` | Forget the last failure, so `check` tries again. |

Run manual deploys under `tmux` or `systemd-run --unit=waffle-deploy-manual …`,
so a dropped SSH session cannot stop a migration halfway: MariaDB commits each
DDL statement on its own.

## Conventions that make it work

- **Everything references `current`.** Compose runs with
  `--project-directory /opt/<slug>/current`; the crontab and
  `tools/backup-prod-db.sh` use the same path.
- **Nothing that must survive a release lives inside one.** The MariaDB data
  directory moves out of the tree: `docker-compose.prod.yml` mounts
  `/opt/<slug>/shared/mysql` instead of `./docker/data/mysql`. Inside a
  release it would be a new, empty database on every deploy.
- **The project's own server config ships with the release**: its Caddyfile
  and Compose files are validated before the switch and rolled back with it.
  **The host's config does not**: systemd units, the timer, the firewall,
  Docker, the Doppler CLI and token, and the edge Caddy on a shared droplet
  are installed by hand from the reference copies in `deploy/`, with a backup
  and a line in a server-side `CHANGES.md`.
- **The health endpoint** is `/cron/health`: cheap, on the loopback-only port,
  and it reaches the database.
- **Alerts:** every failure or refusal goes to the alert channel, the one the
  advisory check uses (docs/supply-chain.md, OPEN-QUESTIONS.md); successes are
  logged to the journal.
- **Never patch a live release in place** (AGENTS.md §9): fix it in the
  repository and release. If an emergency ever forces it, open a PR with the
  identical change at once and compare file checksums before the next
  release, or the next deploy silently reverts the fix.

## Failure list

1. The GitHub API is unreachable or rate-limited: `check` exits and tries on
   the next tick; alert after an hour of failures.
2. A release is published without assets, or they are still uploading: wait,
   alert once after N minutes.
3. The checksum does not match: refuse, alert, keep the files for inspection.
4. The release is older than live, or its publisher is not approved: refuse,
   alert once.
5. The unpacked tree is incomplete: refuse before anything stops.
6. The new Caddyfile does not validate, or the image does not build: stop with
   the old release serving.
7. A migration fails: stop, the old release stays live, the worker restarts on
   it.
8. The health check or the HTTPS check fails after the switch: roll back,
   alert.
9. A second tick starts during a deploy: the lock skips it.
10. Doppler is unreachable: nothing starts; `check` alerts and tries again.
11. The server reboots mid-deploy: a `.partial` directory is removed on the
    next run, and `current` is still a whole release, because the switch is a
    rename.
12. The disk fills: prune runs before unpacking; refuse with an alert if
    there is still no room.

## To build

- `deploy/waffle-deploy`, the systemd units and the conf template in the
  repository, with tests against stand-in `gh`, `curl`, `docker` and `doppler`
  for every item in the failure list, written first (AGENTS.md §7)
- the `build` job attaching the tarball and checksum; the `ship` job, the
  `prod-deploy` config and the `prod` environment's token removed
- `/opt/<slug>/shared/` for the MariaDB data, `.deploy/` and `backups/`, and a
  one-time move from today's layout
- docs/fresh-install.md and docs/releasing.md rewritten for it, and AGENTS.md
  §8 and §9 (the read-only release token on the server)
- the same in Waffle Site, plus the scoped rebuild as a `.d` release

## Adapting to a new project

1. Create the `deploy` user, `/opt/<slug>/{releases,shared}`, and the Doppler
   token scoped to `/opt/<slug>`.
2. Put `GITHUB_RELEASES_TOKEN` (fine-grained, read-only on this repository)
   and the alert target in `prod-serve`.
3. Install `waffle-deploy`, the timer and service units, and
   `/etc/waffle-deploy/<slug>.conf` (`REPO`, `DEPLOY_AUTHORS`, `KEEP`, the
   hostname), from `deploy/`.
4. Enable `waffle-deploy@<slug>.timer`.
5. Publish a release with `./tools/release.sh` and watch
   `journalctl -u waffle-deploy@<slug>` and `waffle-deploy status <slug>`.
