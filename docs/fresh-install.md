# Fresh install, from nothing

Every account, credential and command, in order, from an empty Doppler
workplace to the app serving on its own hostname. `waffle` below is the slug
`tools/rename-project.sh` set, and `waffle.example.com` the hostname.

## Part 0 — Decide before you start

- The production hostname, and where its DNS is managed.
- The server: a single Digital Ocean droplet (Ubuntu LTS, 2 GB RAM is plenty
  to start). IPv4 is enough. An AAAA record is only for a droplet with IPv6
  turned on: one pointing anywhere else breaks Let's Encrypt, which tries IPv6
  first (`deploy/check-dns.sh` refuses it).
- Who the first owner is (an email address).

## Part A — Workstation

**1.** Install Docker (Compose v2.24+), Node 26, the Doppler CLI and `gh`.

**2.** Run the local gate once, so you know the tree you are about to ship is
green:

```bash
./tools/test.sh --coverage
```

## Part B — Doppler

**3. The project and its two configs.** Create project `waffle` and delete the
environments Doppler starts it with. Create two, named for who reads them,
each the root config of its own environment and inheriting nothing:

- `prod-serve` — the application's production secrets, read only by the server.
- `prod-deploy` — the four deploy credentials, read only by GitHub Actions. It
  must not inherit from `prod-serve`: the CI runner that reads it must never
  see a production secret.

There is no local config: local development needs no secrets (every value
has a default in `docker-compose.yml`). When a feature first needs a real key
locally, add a `dev` environment then (AGENTS.md §8).

**4. Sign the CLI in** on your workstation, for the next two steps:

```bash
doppler login
```

**5. Generate the production secrets nobody issues to you.** Piped straight in,
so they never appear on a command line:

```bash
for k in CRON_SECRET DB_PASSWORD DB_ROOT_PASSWORD; do
  openssl rand -hex 32 | tr -d '\n' | doppler secrets set "$k" -p waffle -c prod-serve >/dev/null
done

doppler secrets set -p waffle -c prod-serve \
  DB_HOST=db DB_PORT=3306 DB_NAME=waffle DB_USER=waffle \
  APP_ENV=production APP_DEBUG=false
```

| Key (`prod-serve`) | Notes |
| --- | --- |
| `CRON_SECRET` | Empty denies every `/cron/*` call; `deploy.sh` refuses to install the crontab without it. |
| `DB_PASSWORD` | The app user's password. |
| `DB_ROOT_PASSWORD` | Read by MariaDB **only on first boot** of an empty data directory; changing it later changes nothing. |
| `APP_ENV`, `APP_DEBUG` | Also pinned in `docker-compose.prod.yml`; these are belt and braces. |

**6. The first owner.**

```bash
doppler secrets set -p waffle -c prod-serve FIRST_USERNAME=you@example.com
doppler secrets set -p waffle -c prod-serve FIRST_USER_PASSWORD     # paste, Enter, Ctrl-D
```

Read once, by the `CreateInitialOwner` migration on the first deploy.

> **These must be in `prod-serve` before the first release.** If they are empty when
> that migration first runs it prints "skipped", and phinx records it as run
> anyway, so deploying again does not retry it. Recovery: delete its row from
> `phinxlog` and run `phinx migrate -e production` again (it is idempotent by
> email).

## Part C — The server

**7. Create the droplet** (Ubuntu LTS) with your own SSH key, and point an A
record for `waffle.example.com` at it (or at a reserved IP assigned to it).
Firewall: 22 from your addresses; 80, 443 and 443/udp from anywhere. Port 80
stays open for good: Let's Encrypt validates over it, and Caddy redirects
everything else on it to HTTPS.

**8. Install what the deploy needs**, as root:

- Docker Engine and the Compose plugin from Docker's own apt repository
  (https://docs.docker.com/engine/install/ubuntu/). Compose **v2.24 or later**
  is required: `docker-compose.prod.yml` uses `!override`.
- The Doppler CLI (https://docs.doppler.com/docs/install-cli).
- `curl`, `rsync` and `bind9-dnsutils` (for `dig`, which the deploy's DNS check
  uses) from Ubuntu's own repositories. No certbot: Caddy handles the certificate.

Then the deploy user and the directories:

```bash
adduser --disabled-password --gecos '' deploy
usermod -aG docker deploy
mkdir -p /opt/waffle && chown deploy:deploy /opt/waffle
```

Install a **deploy key** for the `deploy` user: a new ed25519 keypair made on
your workstation (`ssh-keygen -t ed25519 -f ~/.ssh/waffle_deploy`), its public
half in `/home/deploy/.ssh/authorized_keys`. Its private half goes to Doppler
in step 12 and nowhere else.

**9. Give the server its Doppler token.** Doppler → `prod-serve` → Access → a
**read-only service token**. Type it interactively, as `deploy`, scoped to the
app directory:

```bash
sudo -iu deploy
doppler configure set token dp.st.prod-serve.XXXX --scope /opt/waffle
cd /opt/waffle && doppler secrets --only-names      # must list the prod-serve keys
```

**10. HTTPS: nothing to install.** Caddy gets the certificate for the hostname
in `docker-compose.prod.yml`'s `SITE_ADDRESS` on the first deploy, and renews
it on its own from then on (docs/https.md). It needs two things, and the deploy
checks both:

- **DNS points here.** Before anything starts, `deploy.sh` asks a public
  resolver where `waffle.example.com` leads and stops with what to fix if it is
  not this droplet. Check it yourself any time, on the server:

  ```bash
  bash /opt/waffle/deploy/check-dns.sh waffle.example.com
  ```

  Behind a proxy such as Cloudflare, whose addresses are not the droplet's,
  deploy with `SKIP_DNS_CHECK=1`.
- **Ports 80 and 443 are open to everyone** (step 7). After the stack is up,
  `deploy.sh` waits for HTTPS to answer with a valid certificate, and says
  where to look if it does not.

The certificates live in the `caddy_data` Docker volume. Never
`docker compose down -v` on this server: a wiped store means a new certificate
on every deploy, until Let's Encrypt's rate limit locks the hostname out.

**11. Optional: a heartbeat and a deploy alert.**

- `HEARTBEAT_URL` in **`prod-serve`**: an `https://` URL from a dead-man's switch
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

> Doppler will say this config is "missing" keys compared with `prod-serve`. **Ignore
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

Steps 13 and 14 are one command, once `prod-deploy` is filled (step 12) and
`gh` and `doppler` are signed in:

```bash
./tools/setup-github.sh
```

It creates the `prod` environment with only the `v*` tag rule, checks that
`prod-deploy` holds the deploy credentials and nothing more, and pipes a new
read-only token straight from Doppler into the environment secret, so the
token is never shown or put on a command line. It is safe to re-run; it
replaces an existing token only with `--rotate-token`. Required reviewers
(step 14.3) stay a manual choice. Its failure list is in the appendix.

**15. Protect `main`** (Settings → Branches): pull requests required.

## Part E — The first release

**16.** Record the first release's notes under `## [Unreleased]` in
`CHANGELOG.md`, commit, then (AGENTS.md §9, docs/releasing.md):

```bash
./tools/release.sh      # v1: version, changelog, gate, tag, push, GitHub release
gh run watch            # the deploy it started
```

| Symptom | Cause |
| --- | --- |
| `release tag is not vN` | the release was not cut by `./tools/release.sh`; delete it and run the script |
| `this tree is not a complete release` | the runner's build did not land `vendor/` or `src/public/assets` |
| `Doppler is installed but cannot read secrets` | step 9 skipped, not scoped to `/opt/waffle`, or not set as `deploy` |
| `CRON_SECRET is not set in Doppler` | `prod-serve` incomplete (step 5) |
| `has no A record` / `points at …, but this server is …` | DNS (step 7) is missing or wrong, or has not spread yet; fix it and release again |
| `has an AAAA (IPv6) record … that is not this server` | remove the AAAA record, or turn on IPv6 for the droplet and point it there |
| `Caddy cannot load docker/caddy/Caddyfile` | a Caddyfile change that does not parse; nothing was restarted |
| `is not serving a valid certificate after …s` | ports 80/443 closed (step 7), DNS, or Let's Encrypt's rate limit; `logs --tail=80 caddy` says which |
| rsync fails on the host key | server rebuilt; re-capture `DROPLET_SSH_KNOWN_HOSTS` |

**17. Sign in** at `https://waffle.example.com` as `FIRST_USERNAME`, then delete
`FIRST_USER_PASSWORD` (and optionally `FIRST_USERNAME`) from `prod-serve` — the
migration never reads them again. Change the password once there is a screen
for it.

## Appendix — every configuration key

The authoritative list is the `environment:` block of the `php` service in
`docker-compose.yml`.

| Key | Config | Required | Purpose |
| --- | --- | --- | --- |
| `APP_ENV` | prod-serve | pinned by compose | `production` |
| `APP_DEBUG` | prod-serve | pinned by compose | empty/false: exception details hidden |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` | prod-serve | yes | `db`, `3306`, `waffle`, `waffle` |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | prod-serve | yes | generated (step 5) |
| `CRON_SECRET` | prod-serve | yes | generated (step 5) |
| `FIRST_USERNAME`, `FIRST_USER_PASSWORD` | prod-serve | first deploy only | step 6 |
| `HEARTBEAT_URL` | prod-serve | no | step 11 |
| `DROPLET_HOST`, `DROPLET_USER`, `DROPLET_SSH_KEY`, `DROPLET_SSH_KNOWN_HOSTS` | prod-deploy | yes | step 12 |
| `DEPLOY_ALERT_WEBHOOK` | prod-deploy | no | step 11 |
| `DOPPLER_TOKEN` | GitHub `prod` environment | yes | step 14 |

## Appendix — `tools/setup-github.sh` failure list

Each has a test in `tests/Unit/SetupGithubTest.php`, which runs the script
against stand-in `gh` and `doppler` commands.

1. `gh` is missing or not signed in, and the script fails halfway.
2. `doppler` is missing or not signed in.
3. The repository cannot be worked out (no GitHub remote), and the script
   guesses.
4. The `prod-deploy` config does not exist, and a token is issued for nothing.
5. `prod-deploy` holds keys beyond the deploy credentials (somebody clicked
   "Add missing value"), and a token that can read production secrets is
   handed to CI.
6. A deploy credential is missing from `prod-deploy`, and the first release
   fails at the SSH step instead of here.
7. The `prod` environment already allows a branch, or another tag pattern, to
   deploy, and the script leaves it that way without saying.
8. A re-run adds a second `v*` rule.
9. A re-run silently replaces a working `DOPPLER_TOKEN`.
10. The token appears in the terminal or on a command line, where `ps` and
    shell history would keep it.
11. The token can write to Doppler.

