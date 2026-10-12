# Fresh install, from nothing

Every account, credential and command, in order, from an empty Doppler
workplace to the app serving on its own hostname. `waffle` below is the slug
`tools/rename-project.sh` set, and `waffle.example.com` the hostname.

## Part 0 — Decide before you start

- The production hostname, and where its DNS is managed.
- The server: a single Digital Ocean droplet (Ubuntu LTS, 2 GB RAM is plenty
  to start). IPv4 only unless nginx is given `[::]` listeners — an AAAA record
  with nothing listening on it breaks Let's Encrypt, which prefers IPv6.
- Who the first owner is (an email address).

## Part A — Workstation

**1.** Install Docker (Compose v2.24+), Node 26, the Doppler CLI and `gh`.

**2.** Run the local gate once, so you know the tree you are about to ship is
green:

```bash
./tools/test.sh --coverage
```

## Part B — Doppler

**3. The project and its three configs.** Create project `waffle`, with three
environments named `dev`, `prd` and `prod-deploy`, each the root config of its
own environment and inheriting nothing. `prod-deploy` must not inherit from
`prd`: the CI runner that reads it must never see a production secret.

**4. Bind your checkout.**

```bash
doppler login
doppler setup          # reads doppler.yaml: project waffle, config dev
```

`dev` needs nothing to start; every value falls back to the defaults in
`docker-compose.yml`.

**5. Generate the production secrets nobody issues to you.** Piped straight in,
so they never appear on a command line:

```bash
for k in CRON_SECRET DB_PASSWORD DB_ROOT_PASSWORD; do
  openssl rand -hex 32 | tr -d '\n' | doppler secrets set "$k" -p waffle -c prd >/dev/null
done

doppler secrets set -p waffle -c prd \
  DB_HOST=db DB_PORT=3306 DB_NAME=waffle DB_USER=waffle \
  APP_ENV=production APP_DEBUG=false
```

| Key (`prd`) | Notes |
| --- | --- |
| `CRON_SECRET` | Empty denies every `/cron/*` call; `deploy.sh` refuses to install the crontab without it. |
| `DB_PASSWORD` | The app user's password. |
| `DB_ROOT_PASSWORD` | Read by MariaDB **only on first boot** of an empty data directory; changing it later changes nothing. |
| `APP_ENV`, `APP_DEBUG` | Also pinned in `docker-compose.prod.yml`; these are belt and braces. |

**6. The first owner.**

```bash
doppler secrets set -p waffle -c prd FIRST_USERNAME=you@example.com
doppler secrets set -p waffle -c prd FIRST_USER_PASSWORD     # paste, Enter, Ctrl-D
```

Read once, by the `CreateInitialOwner` migration on the first deploy.

> **These must be in `prd` before the first release.** If they are empty when
> that migration first runs it prints "skipped", and phinx records it as run
> anyway, so deploying again does not retry it. Recovery: delete its row from
> `phinxlog` and run `phinx migrate -e production` again (it is idempotent by
> email).

## Part C — The server

**7. Create the droplet** (Ubuntu LTS) with your own SSH key, and point an A
record for `waffle.example.com` at it. Firewall: 22 from your addresses, 80 and
443 from anywhere.

**8. Install what the deploy needs**, as root:

- Docker Engine and the Compose plugin from Docker's own apt repository
  (https://docs.docker.com/engine/install/ubuntu/). Compose **v2.24 or later**
  is required: `docker-compose.prod.yml` uses `!override`.
- The Doppler CLI (https://docs.doppler.com/docs/install-cli).
- `certbot`, `curl` and `rsync` from Ubuntu's own repositories.

Then the deploy user and the directories:

```bash
adduser --disabled-password --gecos '' deploy
usermod -aG docker deploy
mkdir -p /opt/waffle /var/www/certbot && chown deploy:deploy /opt/waffle
```

Install a **deploy key** for the `deploy` user: a new ed25519 keypair made on
your workstation (`ssh-keygen -t ed25519 -f ~/.ssh/waffle_deploy`), its public
half in `/home/deploy/.ssh/authorized_keys`. Its private half goes to Doppler
in step 12 and nowhere else.

**9. Give the server its Doppler token.** Doppler → `prd` → Access → a
**read-only service token**. Type it interactively, as `deploy`, scoped to the
app directory:

```bash
sudo -iu deploy
doppler configure set token dp.st.prd.XXXX --scope /opt/waffle
cd /opt/waffle && doppler secrets --only-names      # must list the prd keys
```

**10. The first certificate.** nginx will not start without the certificate
`docker/nginx/production.conf` names, so the first one is issued standalone,
with nothing on port 80 yet:

```bash
dig +short waffle.example.com                 # must answer the droplet's address first
certbot certonly --standalone -d waffle.example.com -m you@example.com --agree-tos -n
```

Renewals afterwards should go through the webroot, with no downtime: nginx
already serves `/.well-known/acme-challenge/` from `/var/www/certbot`, which
`docker-compose.prod.yml` mounts. Switch the renewal over by editing
`/etc/letsencrypt/renewal/waffle.example.com.conf` so that `[renewalparams]` has
`authenticator = webroot` and `webroot_path = /var/www/certbot,` and a
`[[webroot_map]]` section maps `waffle.example.com = /var/www/certbot`. Then add
a deploy hook that reloads nginx when a certificate changes,
`/etc/letsencrypt/renewal-hooks/deploy/reload-nginx`, executable:

```bash
#!/bin/sh
docker compose -f /opt/waffle/docker-compose.yml -f /opt/waffle/docker-compose.prod.yml exec -T nginx nginx -s reload
```

Prove renewal works **after the first deploy** (nginx has to be up to answer
the challenge), and put a reminder somewhere to do it again: a renewal that
fails silently lands ninety days later.

```bash
certbot renew --dry-run
```

**11. Optional: a heartbeat and a deploy alert.**

- `HEARTBEAT_URL` in **`prd`**: an `https://` URL from a dead-man's switch
  (healthchecks.io or similar). `deploy.sh` adds a ping to it after every
  successful `/cron/health`, so something outside the app notices when it stops.
- `DEPLOY_ALERT_WEBHOOK` in **`prod-deploy`**: a Slack incoming-webhook URL the
  release workflow posts to when a deploy fails.

## Part D — GitHub

**12. Fill Doppler `prod-deploy`** — exactly four keys:

```bash
doppler secrets set -p waffle -c prod-deploy DROPLET_HOST=waffle.example.com DROPLET_USER=deploy
doppler secrets set -p waffle -c prod-deploy DROPLET_SSH_KEY < ~/.ssh/waffle_deploy
ssh-keyscan -t ed25519 waffle.example.com | doppler secrets set -p waffle -c prod-deploy DROPLET_SSH_KNOWN_HOSTS
```

> Doppler will say this config is "missing" keys compared with `prd`. **Ignore
> it and never click "Add missing value"** — that would hand the CI runner every
> production secret, which is what the split exists to prevent.

Re-capture `DROPLET_SSH_KNOWN_HOSTS` whenever the server is rebuilt; the
workflow uses `StrictHostKeyChecking=yes`.

**13. The CI token.** Doppler → `prod-deploy` → Access → a read-only service
token. It goes into GitHub in the next step and nowhere else.

**14. The `prod` environment.** Repository → Settings → Environments → New
environment `prod`:

1. Deployment branches and tags: selected only, a **tag** rule `v*`.
2. Environment secret `DOPPLER_TOKEN` = the step 13 token. The only GitHub
   secret in the whole system, and an *environment* secret, so no other
   workflow can read it.
3. Optional: required reviewers.

**15. Protect `main`** (Settings → Branches): pull requests required.

## Part E — The first release

**16.** Per AGENTS.md §9: bump `src/frontend/package.json` (and its lock with
`npm install --package-lock-only`), move `[Unreleased]` in `CHANGELOG.md` into a
dated section, run `./tools/test.sh --coverage`, commit, then:

```bash
git tag vX.Y.Z && git push origin main --tags
gh release create vX.Y.Z
gh run watch
```

| Symptom | Cause |
| --- | --- |
| `release tag is not vX.Y.Z` | the tag has another shape; delete it and cut `vX.Y.Z` |
| `this tree is not a complete release` | the runner's build did not land `vendor/` or `src/public/assets` |
| `Doppler is installed but cannot read secrets` | step 9 skipped, not scoped to `/opt/waffle`, or not set as `deploy` |
| `CRON_SECRET is not set in Doppler` | `prd` incomplete (step 5) |
| `nginx is not serving after 60s` | no certificate (step 10), or `production.conf` names another host |
| rsync fails on the host key | server rebuilt; re-capture `DROPLET_SSH_KNOWN_HOSTS` |

**17. Sign in** at `https://waffle.example.com` as `FIRST_USERNAME`, then delete
`FIRST_USER_PASSWORD` (and optionally `FIRST_USERNAME`) from `prd` — the
migration never reads them again. Change the password once there is a screen
for it.

## Appendix — every configuration key

The authoritative list is the `environment:` block of the `php` service in
`docker-compose.yml`.

| Key | Config | Required | Purpose |
| --- | --- | --- | --- |
| `APP_ENV` | prd | pinned by compose | `production` |
| `APP_DEBUG` | prd | pinned by compose | empty/false: exception details hidden |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` | prd | yes | `db`, `3306`, `waffle`, `waffle` |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | prd | yes | generated (step 5) |
| `CRON_SECRET` | prd | yes | generated (step 5) |
| `FIRST_USERNAME`, `FIRST_USER_PASSWORD` | prd | first deploy only | step 6 |
| `HEARTBEAT_URL` | prd | no | step 11 |
| `DROPLET_HOST`, `DROPLET_USER`, `DROPLET_SSH_KEY`, `DROPLET_SSH_KNOWN_HOSTS` | prod-deploy | yes | step 12 |
| `DEPLOY_ALERT_WEBHOOK` | prod-deploy | no | step 11 |
| `DOPPLER_TOKEN` | GitHub `prod` environment | yes | step 14 |
