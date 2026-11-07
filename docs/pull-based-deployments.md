# Pull-based deploys from GitHub Releases

How a release reaches the server. Nothing pushes to it: the server checks
GitHub every minute, and when there is a newer release it pulls it, checks it,
migrates the database, switches to it atomically, checks it is healthy, and
switches back if it is not. CI never has any access to the server.

`<slug>` below is the project's slug (`waffle` here), and `/opt/<slug>` its
directory on the server.

## Overview

```
./tools/release.sh ──▶ GitHub Release vN
                          │  deploy.yml builds it and attaches <slug>-vN.tar.gz + .sha256
                          ▼
server: <slug>-deploy.timer, every minute ──▶ <slug>-pull check   (deploy/pull.sh)
   ├─ guards: vN? newer than live? approved publisher? both files attached? checksum?
   ├─ unpack into /opt/<slug>/releases/vN, then run that release's deploy/deploy.sh:
   │    ├─ complete? Doppler readable? DNS here? Caddyfile valid? images rebuilt if docker/ changed
   │    ├─ queue worker stopped; migrations from the new release, the old one still serving
   │    ├─ /opt/<slug>/current → releases/vN; php, worker and Caddy recreated from it
   │    ├─ /cron/health says the database is ok and names this release's newest migration;
   │    │  HTTPS answers with a valid certificate
   │    └─ if not: current back to the previous release, its containers recreated
   └─ alert on any failure or refusal; keep the newest releases, prune the rest
```

## The pieces

| Piece | What it is |
| --- | --- |
| `.github/workflows/deploy.yml` | On `release: published`: the `build` job (read-only) builds the frontend in containers that see only its source (the build offline), builds `vendor/`, audits both and packs the tree; the `attach` job, the only one that can write and which runs no package manager, uploads `<slug>-vN.tar.gz` and its `.sha256` to the release. No secrets. |
| `deploy/pull.sh` | The poller, installed by hand as `/usr/local/sbin/<slug>-pull`: `check`, `deploy <tag>`, `status`, `retry`. |
| `deploy/systemd/<slug>-deploy.service` + `.timer` | Run `<slug>-pull check` every minute as the `deploy` user. The service holds the settings: `APP_ROOT`, `SLUG`, `REPO`, `DEPLOY_AUTHORS`, `KEEP`. None is secret. |
| `deploy/deploy.sh` | Inside each release: brings that release into service, as above. The poller runs the new release's copy, so the deploy logic is always the new release's. |
| `/opt/<slug>/releases/vN/` | One directory per release. The newest `KEEP` (5) stay, and always the live and previous ones. |
| `/opt/<slug>/current` | The link to the live release. The crontab and the backup use this path. |
| `/opt/<slug>/shared/` | What outlives releases and is not a volume: `deploy/` (state, the lock, the cron header file, logs) and `backups/`. |
| Named volumes | `db_data` (the database) and `storage_data` (uploads). The Compose project is named for the slug, so every release shares them. The certificates are the front Caddy's, in `/opt/edge` (docs/edge.md). |

The poller, the units, Docker, Doppler and the firewall are **server config**:
installed by hand (docs/fresh-install.md, steps 8–10) and never changed by a
release. When a release carries a newer `deploy/pull.sh` than the installed
one, its deploy says so, with the command to install it. The project's own
Caddyfile and Compose files ship with each release, are validated before the
switch, and switch back with it.

## Secrets

All in Doppler's `prod`, read by the deploy user's service token scoped
to `/opt/<slug>` (which covers every release under it). There is no `.env`
file, and CI holds no secret.

- **`GITHUB_RELEASES_TOKEN`**: a fine-grained GitHub token that can read this
  repository's contents and change nothing (docs/accounts.md). The poller uses
  it to see the latest release and download its two files. It reaches `curl`
  through a file only the deploy user can read, removed when the run ends,
  never through `curl`'s arguments.
- **`DEPLOY_ALERT_WEBHOOK`** (optional): a Slack incoming-webhook URL, passed
  to `curl` on its standard input. Every refusal and failure is also in the
  journal (`journalctl -u <slug>-deploy`).
- The app's own secrets reach its containers through `doppler run` exactly as
  before. The two keys above are named in no Compose `environment:` block, so
  no container receives them.

## What is trusted

The server runs whatever is in the release tarball, as the deploy user, who
can run Docker and so is as good as root. Everything here protects that file:

- **Who built it.** The poller deploys only files the workflow's own bot
  (`github-actions[bot]`) uploaded, so a person with write access cannot slip
  in their own tarball and checksum before CI's are attached. The checksum
  only catches a damaged download: it comes from the same place.
- **What built it.** In CI, npm runs only in containers that see the
  frontend's source, the build with no network, and only `index.html` and
  `assets/` come out (AGENTS.md §8). The rest of the tree is the tag's own,
  and composer runs no package's code.
- **Which tag.** A tag ruleset lets only the owner create or move a `v` tag
  (docs/accounts.md), and the poller refuses a release older than live or
  published by someone not in `DEPLOY_AUTHORS`.
- **What is on disk.** A release kept on disk is checked against the list of
  files and checksums it was unpacked with before it is deployed again, and
  the app's containers mount it read-only.

## Commands on the server

| Command | Use |
| --- | --- |
| `<slug>-pull status` | Live, previous, failed and held releases, the lock, what is kept. |
| `<slug>-pull deploy vN` | Deploy one tag, older ones included: **the rollback**. It holds the server there until a newer release is published, or `retry`. It is not guarded by "newer than live" or the publisher, only by the checksum. Releases still on disk deploy without downloading. |
| `<slug>-pull retry` | Forget a failed release and a hold, so the next tick deploys the latest again. |
| `journalctl -fu <slug>-deploy` | Watch a deploy. |

Run a manual deploy under `tmux`, or `systemd-run --unit=<slug>-deploy-manual
--uid=deploy <slug>-pull deploy vN`, so a dropped SSH session cannot stop a
migration halfway: MariaDB commits each DDL statement on its own.

**Code rolls back; the schema does not.** That is why migrations only add,
never rename or drop, in the same release (AGENTS.md §6): for a few seconds
during every deploy the previous release runs against the new schema, and
after a rollback it does so for good.

## Failure list

Each has a test in `tests/Unit/PullTest.php`, which runs `deploy/pull.sh`
against stand-ins for GitHub and Doppler. The order of `deploy/deploy.sh`
is asserted in `tests/Unit/DeployScriptsTest.php`.

1. GitHub is unreachable, answers an error, or answers something that is not a
   release: nothing changes and the tick fails; it is reported once it has
   lasted `API_ALERT_MINUTES` (60), never read as "nothing to do".
2. No release has been published yet: nothing to do.
3. The latest release's tag is not `vN`: refused, reported once.
4. The latest release is the one live: nothing to do, quietly.
5. The latest release is older than the one live (an old tag marked latest
   again): refused, reported once. Versions compare as numbers, so `v10` is
   newer than `v9`.
6. The release was published by someone not in `DEPLOY_AUTHORS`: refused,
   reported once.
7. The release's files are not attached yet, or still uploading: wait without
   failing; reported once if still missing after `ASSET_WAIT_MINUTES` (15).
8. The tarball does not match its checksum: refused, nothing kept, reported
   once. This holds for a named `deploy vN` too.
9. The unpacked tree is incomplete: refused before anything stops.
10. A tick starts while a deploy runs: the lock skips it.
11. A release that failed is not retried on every tick: only after `retry`, or
    when a newer release appears.
12. The release's deploy fails: its tag is recorded, it is reported, and the
    previous release is serving (deploy.sh switched back, or never switched).
13. The release token is missing, or Doppler cannot be read: nothing is
    fetched; reported once.
14. A run was interrupted halfway through unpacking: the half-unpacked
    directory is removed before unpacking again. `current` is always a whole
    release, because it moves by a rename.
15. The disk fills with releases: the newest `KEEP` stay, and always the live
    and previous ones.
16. The release token or the alert webhook appears on a command line, or the
    token is left on disk.
17. The release's files were uploaded by someone other than the workflow's
    bot: refused, reported once.
18. A release on disk has changed since it was unpacked (a file edited, added
    or removed): refused, reported once. Removing its directory downloads it
    again.
19. The poller is run as anyone but the deploy user: refused before it
    touches anything, so its lock and state stay the deploy user's.
20. A rollback by name is undone by the next tick: after `deploy vN`, the
    server holds there until a release newer than what was latest is
    published, or `retry`. A rollback while GitHub cannot be read holds
    whatever the first check after it finds.

A tag that is not `vN` is never repeated in an alert, and alerts are escaped
for Slack, so a crafted tag cannot post a link or mention a channel.

And in `deploy/deploy.sh`, before `current` moves: an incomplete release,
Doppler unreadable, `CRON_SECRET` missing, DNS wrong on a first deploy, a
Caddyfile that does not load, the database not healthy, or a migration that
fails all stop with the previous release still serving. After it moves:
php-fpm not starting, `/cron/health` not healthy or naming another migration,
or HTTPS not serving a valid certificate all switch back.
