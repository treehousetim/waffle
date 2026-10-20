# Setups: the ways to build with Waffle

Waffle is two templates: **Waffle** (this repository: a PHP API and a Vue
single-page app, the system of record) and
[**Waffle Site**](https://github.com/treehousetim/waffle-site) (an Astro site of
static pages that search engines read). Every project combines them in one of
the setups below. Choose one before the first line of code, write it in
AGENTS.md §1, and change it only as a decision.

| Setup | Waffle app | Waffle Site | Search engines see | Choose it when |
|---|---|---|---|---|
| **1. App** | yes, on the main domain | no | nothing; it is behind a login | the product is a tool people sign into: a dashboard, an internal system |
| **2. App + Site** | yes, on `app.` | yes, on the main domain | the site, with pages from the app's data | people find you in search, then sign in or buy |
| **2a. Back office + Site** | yes, on `app.`, staff only | yes, on the main domain | the site | a public business site whose staff manage content, listings and leads; customers never sign in |
| **3. Site** | elsewhere, or not at all | yes, on a static host | the site | a marketing site with no server of its own; its forms and live areas talk to a waffle app hosted separately |
| **4. One app, several sites** | yes, on its own domain | several, one per brand or place | each site | one business, several public faces: brands, franchise locations, regions |
| **5. API only** | yes, without its Vue app | no | nothing | the backend for a mobile app or partner integrations |

**Not supported: public pages rendered on each request.** A marketplace whose
logged-out visitors browse listings that change by the minute, at hundreds of
thousands of pages, needs an application server rendering and caching every
public page. Waffle Site has no server by design (static files are fast,
cheap and cannot be attacked like an application), and the Vue app is not
read well by search engines. Waffle Site's docs/data-pages.md shows how far
built pages with live islands go; past that, this is a different
architecture, chosen deliberately, not grown into.

## Terms

| Term | Meaning |
|---|---|
| **app** | A project made from this template: the API, the Vue single-page app, MariaDB, run with Docker Compose. |
| **site** | A project made from Waffle Site: static pages built by Astro, served as files. |
| **site API** | The app's `/api/site/*` endpoints: the only part of the app a site talks to (Waffle Site's docs/waffle-api.md). |
| **site key** | The secret a site's server sends with every site API request (`X-Site-Key`), so the app knows which site is asking. Never in a browser. |
| **island** | A small live part of a static page (a form, a calendar, a price) that talks to the site API from the browser. |
| **edge** | On one server running both an app and a site: the one web server that holds the certificates and routes each hostname to its project. |
| **content model** | The tables the app uses for anything a site publishes: docs/content-model.md. |

## Setup 1: App

The template as it stands: one Compose project on one server, Caddy with
automatic HTTPS in front of PHP and the built Vue app, MariaDB, deployed by
GitHub Actions on each release (docs/releasing.md).

- **Search engines:** none; pages behind a login are not content.
- **Content model:** not needed until something is published.
- **Built today:** all of it, except a `robots.txt` that disallows everything
  (the app serves none yet; a crawler finds only the sign-in page).

## Setup 2: App + Site

The app on `app.example.com`, the site on `example.com` (and `www.`). The
site's pages are built from the app's data (Waffle Site's
docs/data-pages.md); its forms and live areas post and fetch through the site
to the app; the app tells the site when published records change, and the
site rebuilds only the pages affected.

```
visitor ── example.com ──► site: static pages, /api/site/* forwarded ──► app
staff   ── app.example.com ──► app: API + Vue app ──► MariaDB
app ── change notice (queued) ──► GitHub Actions ──► site: scoped rebuild
```

- **The site's server forwards `/api/site/*` to the app**, adding the site key
  and dropping the visitor's cookies, so the browser only ever talks to the
  site's own origin.
- **The site's build reads the app over HTTPS**, with the site key, on the
  GitHub runner.
- **The app owns the content model** (docs/content-model.md) and the change
  notices (AGENTS.md §4).

### Two servers (built today)

Each project on its own droplet, each with its own Caddy and its own
certificate, deployed by its own workflow. The default, and the simplest:
neither can take the other down, and each scales alone.

### One server (not built yet)

Both projects on one droplet. Two Caddies cannot both own ports 80 and 443,
so one server needs an **edge**: a single Caddy, in its own Compose project,
that holds every certificate and routes each hostname to its project's own
Caddy over a shared Docker network. Each project's Caddy then serves plain
HTTP inside the network (the Caddyfile already chooses plain HTTP or HTTPS
from `SITE_ADDRESS`), and the site forwards `/api/site/*` to the app over the
network rather than the internet. To build: the edge's Compose file and
Caddyfile, `SITE_ADDRESS` and network settings in both templates, and the
deploy scripts' knowledge of which of the two they are restarting.

## Setup 2a: Back office + Site

Setup 2 with an app whose only users are staff: no customer accounts, no
customer pages. The app is the place to edit what the site publishes, read
form submissions and run the business; the site is everything the public
sees. The same infrastructure as setup 2 (two servers or one); the
difference is the app's own pages: content editing, media, the form inbox,
the site's rebuild status. Most small-business projects are this setup.

## Setup 3: Site

Waffle Site alone, as static files on a static host: no server of its own,
no Docker in production. Locally it runs exactly as in the other setups (the
dev server and Caddy in Docker); remotely, a static host serves the files.

- **Publishing is a GitHub release, as in every setup.** `./tools/release.sh`
  cuts `vN`; the release workflow builds that tag (type check, build, SEO
  audit; any error stops it) and hands the files to the host, committing
  them to a `publish` branch the host deploys from. Rollback is the workflow
  run for an older tag. **DigitalOcean App Platform's free static sites** are
  supported out of the box (Waffle Site's docs/setups.md has the workflow and
  the app spec); any static host that serves a directory works.
- **Forms and live areas are islands talking to a waffle app's site API**
  directly from the browser, because there is no server to forward through.
  That app is hosted elsewhere (setup 1, 2 or 4's app, or one kept only for
  this). Going straight to the app changes three things, all in the app's
  site API:
  - **No site key from the browser.** A key in a browser is not a secret. The
    site API's public endpoints (form submissions, live reads) accept requests
    without one, and protect themselves instead: an allow-list of the site's
    origin (CORS), the honeypot, rate limits per address, size limits, and
    nothing returned that is not public.
  - **The site's content security policy names the app's origin** in
    `connect-src` and `form-action`, and nothing else.
  - **The app's Caddy answers the site's origin** (CORS preflight), and still
    never accepts a credentialed request from it.
- **Build-time reads need no key**: the content site API is public and
  read-only (docs/content.md, "Site API"), so a build on a GitHub runner
  reads it as anyone could.
- **Headers the host cannot set.** The content security policy is in every
  page (Astro writes it), so it works on any host. What only a header can
  carry (refusing to be framed, HSTS) depends on the host; record what yours
  sets.
- **Built today:** the site itself. **Not built yet:** the publish workflow
  and app spec for App Platform, the content security policy taking the app's
  origin from settings, and the app's public site API endpoints (CORS and rate
  limits).

## Setup 4: One app, several sites

One app feeding several sites, each a separate Waffle Site project on its own
domain. Every site has its own site key; the key identifies the site, and the
site API answers each with only its own records. The content model's `sites`
table and its join tables say which records each site publishes
(docs/content-model.md, "Several sites"). Change notices go to each site
whose pages a change affects, separately. Infrastructure as setup 2: one
server with an edge, or several.

**Built today:** nothing specific; one site per app. **Not built yet:** the
`sites` table, per-site keys, and the site API scoped by key.

## Setup 5: API only

The app without its Vue single-page app, as a backend for a mobile app or for
other systems. The API, the access levels, the content model, the queue: all
as in setup 1.

- **Authentication differs.** The app signs people in with a session cookie,
  which suits a browser on the same domain. A mobile app or another system
  needs tokens: issued, scoped, expiring and revocable. Decide the scheme
  before building (an open question in OPEN-QUESTIONS.md when chosen).
- **Built today:** the API and its tests; the Vue app can be left unbuilt.
  **Not built yet:** token authentication.

## Versions

Waffle and Waffle Site are released separately, with **matching majors**: a
Waffle Site 1.x site works with a Waffle 1.x app, and a breaking change to
the site API is a major release of both. Each template's changelog says what
a project porting a change needs to do; a project owns its code from setup
on, and there is no upgrade tool.

## What every setup shares

- One rulebook per project: AGENTS.md, test first, 100% coverage, the gate.
- Secrets only in Doppler; the user types every value.
- Releases through `./tools/release.sh` and GitHub Actions, in every setup;
  only where the built files go differs.
- Pages a search engine should read come only from Waffle Site, built as
  static HTML; the Vue app is for people who have signed in.
