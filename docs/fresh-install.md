# Fresh install, from nothing

Every account, key and command, in order, from nothing to the app serving on
its own hostname. `waffle` below is the slug `tools/rename-project.sh` set,
and `waffle.example.com` the hostname.

## Part 0 — Decide before you start

- The production hostname, and where its DNS is managed.
- The server: a single DigitalOcean droplet (Debian 13, 2 GB RAM is plenty
  to start). IPv4 is enough. An AAAA record is only for a droplet with IPv6
  turned on: one pointing anywhere else breaks Let's Encrypt, which tries IPv6
  first (`deploy/check-dns.sh` refuses it).
- Who the first owner is (an email address).

## Part A — Workstation

**1.** Install Docker (Compose v2.24+), the Doppler CLI and `gh`.

**2.** Run the local gate once, so you know the tree you are about to ship is
green:

```bash
./tools/test.sh --coverage
```

## Part B — Accounts and keys

**3. The accounts.** Create each account in docs/accounts.md, and in Doppler
the project `waffle` with the config `prod`. Put the GitHub token
(`GITHUB_RELEASES_TOKEN`) into `prod` as that page says.

**4. Sign the Doppler CLI in** on your workstation, for the next two steps:

```bash
doppler login
```

**5. Generate the production secrets nobody issues to you.** Piped straight in,
so they never appear on a command line:

```bash
for k in CRON_SECRET DB_PASSWORD DB_ROOT_PASSWORD; do
  openssl rand -hex 32 | tr -d '\n' | doppler secrets set "$k" -p waffle -c prod >/dev/null
done

doppler secrets set -p waffle -c prod \
  DB_HOST=db DB_PORT=3306 DB_NAME=waffle DB_USER=waffle \
  APP_ENV=production APP_DEBUG=false
```

| Key (`prod`) | Notes |
| --- | --- |
| `CRON_SECRET` | Empty denies every `/cron/*` call; the deploy refuses to run without it. |
| `DB_PASSWORD` | The app user's password. |
| `DB_ROOT_PASSWORD` | Read by MariaDB **only on first boot** of an empty data volume; changing it later changes nothing. |
| `APP_ENV`, `APP_DEBUG` | Also pinned in `docker-compose.prod.yml`; these are belt and braces. |

**6. The first owner.**

```bash
doppler secrets set -p waffle -c prod FIRST_USERNAME=you@example.com
doppler secrets set -p waffle -c prod FIRST_USER_PASSWORD     # paste, Enter, Ctrl-D
```

Read once, by the `CreateInitialOwner` migration on the first deploy.

> **These must be in `prod` before the first release.** If they are empty when
> that migration first runs it prints "skipped", and phinx records it as run
> anyway, so deploying again does not retry it. Recovery: delete its row from
> `phinxlog` and run `phinx migrate -e production` again (it is idempotent by
> email).

## Part C — The server

**7. Create the droplet** (Debian 13) with only your own SSH key
(docs/accounts.md), and point an A record for `waffle.example.com` at it.
Firewall: 22 from your addresses; 80, 443 and 443/udp from anywhere. Port 80
stays open for good: Let's Encrypt validates over it, and the front Caddy
redirects everything else on it to HTTPS. Password logins stay off: the droplet is made
with a key, and only keys open it.

**8. Install what the deploy needs**, as root:

- Docker Engine and the Compose plugin from Docker's own apt repository
  (https://docs.docker.com/engine/install/debian/). Compose **v2.24 or later**
  is required: `docker-compose.prod.yml` uses `!override`.
- The Doppler CLI from Doppler's own apt repository
  (https://docs.doppler.com/docs/install-cli).
- `jq` and `bind9-dnsutils` (for `dig`, which the DNS check uses) from Debian's
  own repositories. `curl` is already there, and `unattended-upgrades`, which
  installs Debian's security updates on its own. No certbot: Caddy handles the
  certificate.

Then the deploy user and its directories:

```bash
adduser --disabled-password --gecos '' deploy
usermod -aG docker deploy
mkdir -p /opt/waffle/releases /opt/waffle/shared/deploy /opt/waffle/shared/backups
chown -R deploy:deploy /opt/waffle
```

**9. Give the server its Doppler token**, the read-only service token from
docs/accounts.md. This is the only key ever typed on the server. As `deploy`,
so the token is the deploy user's and scoped to the app's directory:

```bash
sudo -iu deploy
read -rs -p 'Doppler token: ' T && printf %s "$T" | doppler configure set token --scope /opt/waffle; unset T
cd /opt/waffle && doppler secrets --only-names      # must list the prod keys
exit
```

`read -s` keeps the token off the screen and out of the shell's history, and
piping it in keeps it out of every command's arguments, which any process on
the server can read.

**10. Install the poller and its timer**, from your workstation's copy of the
repository, as root on the server. They are server config, so a release never
changes them (docs/pull-based-deployments.md):

```bash
# on your workstation
scp deploy/pull.sh deploy/systemd/waffle-deploy.service deploy/systemd/waffle-deploy.timer root@waffle.example.com:

# on the server, as root
install -m 755 ~/pull.sh /usr/local/sbin/waffle-pull
install -m 644 ~/waffle-deploy.service ~/waffle-deploy.timer /etc/systemd/system/
rm ~/pull.sh ~/waffle-deploy.service ~/waffle-deploy.timer
systemctl daemon-reload
systemctl enable --now waffle-deploy.timer
```

Fill in the two settings in `waffle-deploy.service` first, in your
repository, and commit them: `REPO` is the project's GitHub repository
(`acme/acme-app`), and `DEPLOY_AUTHORS` the GitHub logins whose releases
deploy. Both are empty in a new project, and the poller refuses to run
without them.

**11. The front Caddy**, which owns ports 80 and 443 for every project on the
server and holds their certificates: set it up as docs/edge.md says, once per
server, before the first deploy. It gets the certificate for
`waffle.example.com` as soon as DNS points here, and renews it on its own
(docs/https.md). The deploy checks DNS and the front Caddy before anything
starts, and after the switch waits for HTTPS to answer with a valid
certificate.

Never `docker compose down -v` in `/opt/edge`: a wiped certificate store means
new certificates for every hostname, until Let's Encrypt's rate limit locks
them out.

## Part D — The first release

**12.** Record the first release's notes under `## [Unreleased]` in
`CHANGELOG.md`, commit, then (AGENTS.md §9, docs/releasing.md):

```bash
./tools/release.sh      # v1: version, changelog, gate, tag, push, GitHub release
gh run watch            # the build, which attaches waffle-v1.tar.gz to the release
```

Within a minute of the files being attached, the server deploys it. Watch it
there:

```bash
journalctl -fu waffle-deploy
waffle-pull status
```

| Symptom | Cause |
| --- | --- |
| `GITHUB_RELEASES_TOKEN is not set in Doppler` | the key is missing from `prod` (step 3), or the token in step 9 is not the deploy user's |
| `GitHub's API has been failing (last answer: HTTP 401)` | the GitHub token expired or was revoked; make a new one (docs/accounts.md) |
| `waiting for v1's assets`, then `still has no tarball` | the build failed; `gh run view` says why |
| `published by …, who is not in DEPLOY_AUTHORS` | add that login to the unit's `DEPLOY_AUTHORS` (step 10) |
| `Doppler is installed but cannot read secrets` | step 9 skipped, not scoped to `/opt/waffle`, or not set as `deploy` |
| `CRON_SECRET is not set in Doppler` | `prod` incomplete (step 5) |
| `has no A record` / `points at …, but this server is …` | DNS (step 7) is missing or wrong, or has not spread yet; fix it, then `waffle-pull retry` |
| `has an AAAA (IPv6) record … that is not this server` | remove the AAAA record, or turn on IPv6 for the droplet and point it there |
| `there is no edge network` | the front Caddy is not set up (step 11, docs/edge.md) |
| `Caddy cannot load this release's docker/caddy/Caddyfile` | a Caddyfile change that does not parse; nothing was restarted |
| `is not serving a valid certificate after …s` | ports 80/443 closed (step 7), DNS, or Let's Encrypt's rate limit; the front Caddy not set up or not naming the hostname (docs/edge.md); `docker compose logs --tail=80 caddy` in `/opt/waffle/current` says which |

**13. Sign in** at `https://waffle.example.com` as `FIRST_USERNAME`, then delete
`FIRST_USER_PASSWORD` (and optionally `FIRST_USERNAME`) from `prod` — the
migration never reads them again. Change the password once there is a screen
for it.

## Appendix — every configuration key

The authoritative list of the app's own keys is the `environment:` block of
the `php` service in `docker-compose.yml`. All of them are in Doppler's
`prod`; nothing is kept anywhere else.

| Key | Required | Purpose |
| --- | --- | --- |
| `APP_ENV` | pinned by compose | `production` |
| `APP_DEBUG` | pinned by compose | empty/false: exception details hidden |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` | yes | `db`, `3306`, `waffle`, `waffle` |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | yes | generated (step 5) |
| `CRON_SECRET` | yes | generated (step 5) |
| `FIRST_USERNAME`, `FIRST_USER_PASSWORD` | first deploy only | step 6 |
| `GITHUB_RELEASES_TOKEN` | yes | the server's read-only GitHub token (docs/accounts.md); read by the deploy, never by the app |
| `SITE_REPOSITORY`, `SITE_DISPATCH_TOKEN` | for change notices | docs/content.md and docs/accounts.md |
| `RESEND_API_KEY` | once mail is built | docs/accounts.md |
| `HEARTBEAT_URL` | no | docs/accounts.md |
| `DEPLOY_ALERT_WEBHOOK` | no | where the server reports a failed or refused deploy (docs/accounts.md) |
