# The server's front Caddy

Each project on a server runs its own Caddy: the app, and a Waffle Site beside
it (Waffle Site's setups 2, 2a and 4). Only one thing can listen on ports 80
and 443, so a front Caddy does: `deploy/edge/`. It gets and renews every
hostname's certificate, redirects HTTP to HTTPS, and forwards each hostname to
the project that serves it. An app alone on its server runs one too, so the
setup is the same with or without a site. The projects' own Caddyfiles keep
every rule they have (headers, caching, what is refused); they serve plain
HTTP and publish no public port.

```
visitor ──HTTPS──▶ front Caddy (/opt/edge, ports 80 and 443, the certificates)
                     ├─ waffle.example.com  ──edge network──▶ waffle-app:80  the app's Caddy ──▶ php
                     └─ a site's hostname   ──edge network──▶ the site's Caddy ──▶ files
```

- **The `edge` network** is made by the front Caddy's Compose file. Each
  project's `docker-compose.prod.yml` puts its Caddy, and nothing else, on it
  under an alias: `<slug>-app` for the app, and for a site its slug with `_`
  written `-` (`acme_site` is `acme-site`). Never the bare slug: the server
  itself is often named for it, and that name resolves to the server, so the
  front Caddy would proxy to itself.
- **The visitor's address.** The front Caddy sends it in `X-Forwarded-For`,
  throwing away any the visitor sent. The projects' Caddys trust that header
  only from private networks, which nothing on the internet can reach them
  from, and the app's Caddy hands it to PHP as `REMOTE_ADDR`, so login
  throttling and a site's form limit see each visitor, not the front Caddy.
- **HTTPS.** The front Caddy sends `X-Forwarded-Proto: https`, which the app
  reads to mark the session cookie `Secure` (`SessionCookie::overHttps()`).
- **The certificates** are in the front Caddy's `edge_data` volume. Never
  `docker compose down -v` in `/opt/edge`: a wiped store means new
  certificates for every hostname until Let's Encrypt's rate limit locks them
  out for days.
- **Cron** stays on the app's own loopback port, 8081, which the front Caddy
  never reaches.

It is **server config**, like the poller: installed by hand, never changed by
a release, and set up **before either project's first deploy**, because each
project's Caddy joins its network. A deploy refuses to start without it.

## Setting it up

As root on the server, from your workstation's copy of this repository:

```bash
# on your workstation
scp deploy/edge/docker-compose.yml deploy/edge/Caddyfile root@waffle.example.com:

# on the server, as root
mkdir -p /opt/edge
install -m 644 ~/docker-compose.yml ~/Caddyfile /opt/edge/
rm ~/docker-compose.yml ~/Caddyfile
cd /opt/edge && docker compose up -d
```

Until a project is deployed, its hostname answers 502; the certificates are
fetched as soon as DNS points here.

## A site beside the app

A Waffle Site on the same server is on the `edge` network as its slug with
`_` written `-` (`acme_site` is `acme-site`; its `docker-compose.prod.yml`
names it). Add its hostname, and its `www` if it has one, to
`deploy/edge/Caddyfile`, then change it on the server as below:

```
www.acme.com {
	reverse_proxy acme-site:80 {
		lb_try_duration 15s
	}
}

acme.com {
	redir https://www.acme.com{uri} permanent
}
```

Whichever of the two is the site's `SITE_ADDRESS` is the one forwarded; the
other redirects to it. Set the front Caddy up before either project's first
deploy.

## Changing it

A new hostname, or a new Caddy version (which the projects take at the same
time; `tests/Unit/EdgeTest.php` checks they match): change the files here,
copy them to the server's home folder with `scp` as above, and, as root:

```bash
cp ~/Caddyfile ~/docker-compose.yml /opt/edge/ && rm ~/Caddyfile ~/docker-compose.yml
cd /opt/edge
docker compose exec caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile   # a new hostname
docker compose up -d                                                                        # a new image
```

`cp`, not `install` or `mv`: Docker mounts the Caddyfile itself, and a file
replaced rather than written into is one the running Caddy never sees (it
keeps reading the old one until `docker compose up -d --force-recreate`).

A reload drops no connection. A new image restarts the front Caddy, a second
or two without either site.

## Failure list

Each has a test: 1 to 3 in `tests/Unit/CaddyfileTest.php` (and Waffle Site's
`tests/infra/caddyfile.test.js`), 4 and 6 to 11 in `tests/Unit/EdgeTest.php`,
5 in `tests/Unit/DeployScriptsTest.php` (and Waffle Site's
`tests/infra/deploy.test.js`).

1. A project's Caddy publishes 80 or 443 itself, or asks for a certificate,
   and fights the front Caddy for them.
2. A visitor's forged `X-Forwarded-For` becomes the address the app sees, or
   every visitor is seen as the front Caddy's address, and login throttling
   and the form limit then put everyone in one bucket.
3. Something other than a project's Caddy (php, the database) is reachable on
   the `edge` network.
4. The front Caddy answers for a hostname no project serves, or forwards one
   to the wrong project.
5. A deploy starts before the front Caddy exists, and fails inside Compose
   instead of saying what to set up.
6. A deploy's restart of a project's Caddy shows visitors errors: the front
   Caddy waits up to 15 seconds for it instead.
7. The certificate store is wiped, and repeated re-issues hit Let's Encrypt's
   rate limit.
8. The front Caddy runs a different Caddy from the projects', so a security
   fix lands in one and not the other.
9. The cron port is reachable through the front Caddy.
10. A visitor is served over plain HTTP: the front Caddy redirects port 80 to
    HTTPS.
11. A project's alias on the network is a name the server already answers to
    (a droplet named for the slug, say), and the front Caddy proxies to
    itself instead of to the project.
