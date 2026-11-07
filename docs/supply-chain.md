# Supply chain: npm

The attack this defends against: a package maintainer's account is taken over
(phishing, a leaked token) and a poisoned version of a popular package is
published. It does its damage two ways: an **install script**
(`preinstall`/`postinstall`) that steals tokens and keys from whatever machine
installs it, and **code in the bundle** that runs in your users' browsers.
Poisoned versions are usually caught and pulled within hours or days, so the
defence is to never be early, and to give anything that slips through nothing
to reach.

## The layers

1. **Nothing younger than a week.** `./tools/npm.sh` is the only way npm is
   run, and every command that resolves versions (`install`, `update`, `add`
   and their aliases) gets `--before=<7 days ago>`: npm picks only versions
   that had already been public for a week, and moves anything newer in the
   lockfile back to what existed then. `NPM_COOLDOWN_DAYS` changes the
   window; `0` turns it off, loudly, for the rare security fix you cannot wait
   for.
2. **No install scripts.** `src/frontend/.npmrc` sets `ignore-scripts=true`,
   and `tools/npm.sh` sets it again in the environment. None of the
   dependencies needs one (Vite ships its native parts as ordinary
   per-platform packages). `npm run build` and `npm run dev` still run, because
   asking for a script by name is not an install script.
3. **npm runs in a throwaway container.** `tools/npm.sh` runs every npm
   command in `node:26-alpine`, as your user, with only the repository mounted:
   no home directory, so no `~/.ssh`, no `~/.npmrc` token, no `gh` or Doppler
   login, no browser data. `node_modules` lives in a Docker volume
   (`<slug>_node_modules`), never on your disk, so Linux binaries never mix
   with your machine's. Node does not need to be installed on your machine at
   all.
4. **The lockfile is the install.** CI runs `npm ci`, which installs exactly
   the locked versions and checks each against its recorded hash. In CI too
   npm runs only in containers that see `src/frontend` and nothing else, and
   the build, where packages' code runs, has no network; only `index.html`
   and `assets/` come out of it into the release, so a package that got
   through cannot change the code the server runs. No job that runs npm can
   write to the repository, and none holds a secret.
5. **Tampering is checked.** CI runs `npm audit signatures` after `npm ci`:
   it fails on a tarball whose registry signature does not match. (It cannot
   catch a hijacked account publishing normally; that is what layer 1 is for.)
6. **The browser contains the rest.** The content security policy
   (`docker/caddy/Caddyfile`) allows scripts only from the app and connections
   only to the app, so code smuggled into the bundle can neither load more
   code nor send data anywhere else.

Editors that read `node_modules` for types will not find it on disk. That is
the price of layer 3; most Vue tooling copes, and the build is unaffected.

## Using it

```bash
./tools/npm.sh ci                  # install exactly what the lockfile says
./tools/npm.sh run build           # build into src/public
./tools/npm.sh run dev             # dev server on http://localhost:5173
./tools/npm.sh update              # newer versions, a week old or more
./tools/npm.sh install some-pkg    # a new dependency, a week old or more
NPM_COOLDOWN_DAYS=0 ./tools/npm.sh install some-pkg@1.2.4   # an urgent fix
```

Adding a dependency still needs approval under AGENTS.md §2.

## Failure list

Each has a test in `tests/Unit/NpmTest.php`, which runs the script against a
stand-in `docker`.

1. A version published in the last few days, the window hijacked releases
   live in, enters the lockfile through `install` or `update`.
2. A package's install script runs.
3. Code from a package, at install or build time, can read the developer's
   SSH keys, tokens, or browser data.
4. npm is run directly, bypassing all of the above, because a document or a
   script says to.
5. An urgent fix newer than the window cannot be installed at all.
6. A tarball altered after publishing goes unnoticed.
7. CI installs anything but exactly the lockfile.
8. Files the container writes end up owned by root on Linux.
9. `node_modules` is shared between the container and the machine, and the
   native binaries are for the wrong platform.
10. The dev server cannot be reached from the browser, or cannot reach the
    API.
11. The window is set to something that is not a number of days, and is
    silently treated as no window.
12. It runs on a native Windows shell and half-works.

# Supply chain: Composer

The same attack reaches PHP through Packagist, with one difference in how it
runs: Composer never runs a package's own scripts, only **plugins**, and only
the ones `composer.json` names in `allow-plugins`. So the install-time route is
narrower, and the cooldown matters more.

## The layers

1. **Nothing younger than a week, enforced twice.** Composer has no `--before`,
   but `composer.lock` records every package's release time. `./tools/composer.sh`
   is the only way Composer is run; after anything that picks versions
   (`require`, `update`, `remove`), it checks the lock, and if a package is
   younger than `COMPOSER_COOLDOWN_DAYS` (default 7) it puts `composer.json` and
   `composer.lock` back, reinstalls, and names the package. Second, the test
   suite fails on a lock holding a package younger than a week
   (`tests/Unit/ComposerTest.php`), so Composer run any other way is caught
   before a release.
2. **An urgent fix is recorded, not hidden.** `COMPOSER_COOLDOWN_DAYS=0` lets a
   young version in and writes it to `cooldown-exceptions.txt` (package,
   version, date), which the suite honours. The file is reviewed like code;
   an entry can be deleted once its week has passed.
3. **Only named plugins run.** `allow-plugins` lists exactly the plugins in the
   lock (today only Pest's, which is dev-only), never a wildcard.
4. **CI runs no third-party code while installing.** The production build is
   `composer install --no-dev --no-plugins --no-scripts`, from the lock, before
   any secret is loaded.
5. **Known vulnerabilities stop a release.** CI runs `composer audit` against
   the lock after installing and before anything is shipped.
6. **Composer runs in the php container.** `tools/composer.sh` uses
   `docker compose run --rm --no-deps php`: the same PHP and extensions as the
   app, the repository mounted, `vendor/` in its volume, and none of your home
   directory, so no `~/.composer/auth.json` token and no `~/.ssh`.

```bash
./tools/composer.sh install                 # exactly the lock
./tools/composer.sh update                  # newer versions, a week old or more
./tools/composer.sh require vendor/package  # a new dependency (approval first, AGENTS.md §2)
COMPOSER_COOLDOWN_DAYS=0 ./tools/composer.sh update vendor/package   # an urgent fix
```

## Failure list (Composer)

Each has a test in `tests/Unit/ComposerTest.php`; the script is run in a
scratch copy against a stand-in `docker`.

1. A release from the last week enters `composer.lock` through `require`,
   `update` or `remove`.
2. Composer is run some other way and a young release reaches a release
   anyway.
3. A package with no recorded release time is waved through as old.
4. An urgent fix cannot be taken, or is taken without a record.
5. A refused update leaves `composer.json`, `composer.lock` or `vendor/` in the
   new state.
6. A plugin nobody named runs at install time, or a permission outlives the
   plugin it was for.
7. CI runs plugins or scripts while building the production `vendor/`.
8. A known vulnerability ships.
9. Composer, run from a document's instructions, reads the developer's home
   directory.
10. Files Composer writes end up owned by root on Linux.
11. The window is set to something that is not a number of days.
12. It runs on a native Windows shell and half-works.

# Watching for advisories, on the server

**Status: designed, not built.**

The week-long cooldown keeps a hijacked release out; it does nothing about a
flaw found later in a version already running. The server checks for those
itself, once a day, and says so when it finds one. It runs on the server
because the server knows what is actually running, needs no outside service
holding any of its credentials, and keeps working when nobody is looking at
GitHub.

**It reports and never changes anything.** A version is still bumped by hand,
in the repository, with its tests, through a release: the cooldown and the
gate apply to a security fix as to anything else, and the person deciding can
see whether the flaw reaches this project's configuration at all.

### What it checks

| What | The running version, from | Compared with |
| --- | --- | --- |
| Caddy | `docker compose exec caddy caddy version` | the GitHub Advisory Database (`api.github.com/advisories`, Go ecosystem, `github.com/caddyserver/caddy/v2` at that version) |
| PHP | `docker compose exec php php -r 'echo PHP_VERSION;'` | php.net's release list for the same minor: a newer patch is reported, since PHP patch releases carry the security fixes |
| MariaDB | `docker compose exec db mariadb --version` | the newest patch of the same minor from MariaDB's downloads API |
| Composer packages | `docker compose exec php composer audit --locked --no-dev` | Packagist's advisories, against the `composer.lock` that was deployed |
| Debian | `/var/run/reboot-required` | security updates are installed by `unattended-upgrades` (docs/fresh-install.md); the check only reports a reboot they are waiting for |

npm is not checked here: no package from npm runs on the server. The build
audits it, on the runner, on every release.

### How it reports

- **Once per finding.** What has been reported is kept in `shared/deploy/advisories`,
  so the same advisory is not reported every day, and a finding that goes
  away (the version was bumped) is forgotten.
- **To where a person looks.** The same rule as every cron job
  (deploy/crontab): output is discarded, so the outcome has to go somewhere.
  The channel is an open question (OPEN-QUESTIONS.md): an alert webhook in
  `prod`, the heartbeat service's failure ping, or both.
- **A check that cannot run is itself reported**, after two days in a row, so
  a broken check does not look like a quiet one.

### Failure list

1. A source is unreachable or slow: each request has a timeout; that source is
   skipped and counted toward (7), and the others still run.
2. A source answers something unexpected (an error page, a changed format):
   treated as (1), never as "no advisories".
3. A container is not running, so its version cannot be read: reported as
   such, not skipped silently.
4. The same advisory every day: reported once (`shared/deploy/advisories`).
5. A version string the check cannot parse: reported, not compared.
6. A rate limit from the GitHub API (60 requests an hour without a token):
   one request per component per day stays far below it; a 403 is (1).
7. The check has not completed for two days: reported.
8. Nowhere to report to (no channel configured): `deploy.sh` warns on every
   deploy, as it does for a missing `HEARTBEAT_URL`.
9. The check must never print, store or send a secret: it reads versions
   only, and the alert carries the component, the version and the advisory's
   link.

### To build

- `deploy/check-advisories.sh`, run daily from `deploy/crontab` (outside the
  busy hours, after the nightly backup), with its state in `shared/deploy/`
- the reporting channel, once chosen, rendered into the crontab the way
  `HEARTBEAT_URL` is
- `unattended-upgrades` in docs/fresh-install.md
- tests in `DeployScriptsTest` against stand-in `docker` and `curl`, one per
  item above, written first (AGENTS.md §7)
