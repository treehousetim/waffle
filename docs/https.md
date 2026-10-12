# HTTPS

Caddy is the web server, locally and in production, from one file:
`docker/caddy/Caddyfile`. In production it obtains the certificate for the
site's hostname from Let's Encrypt on its own, renews it on its own, and
redirects plain HTTP to HTTPS. There is no certbot, no renewal hook, and no
certificate path to keep in step with anything.

## Where the hostname lives

Once: `SITE_ADDRESS` in `docker-compose.prod.yml`, a literal that
`tools/rename-project.sh` writes. The Caddyfile reads it as `{$SITE_ADDRESS}`.
Locally the base compose file sets it to `:80`, a port with no hostname, which
Caddy serves over plain HTTP and never tries to get a certificate for.

It is not a Doppler value on purpose: `deploy.sh` reads it to check DNS before
anything starts, and a literal in the repo is one less thing to get wrong
per environment.

## Launching: what you do, and what checks it

1. **Point DNS at the droplet.** An A record for the hostname, to the droplet's
   address (or its reserved IP). **No AAAA record** unless the droplet has IPv6
   turned on: Let's Encrypt tries IPv6 first, and a record pointing nowhere
   fails the certificate.
2. **Open the firewall.** 80 and 443 from anywhere (443/udp too, for HTTP/3).
   Port 80 has to stay open after launch: Let's Encrypt validates over it, and
   Caddy redirects everything else on it to HTTPS.
3. **Release.** `deploy.sh` checks DNS first (`deploy/check-dns.sh`), against a
   public resolver so it sees what Let's Encrypt sees, and stops with what to
   fix if the name does not point here. On the first deploy that is a refusal;
   afterwards a warning, because the site is already live and an emergency fix
   should not wait on DNS. `SKIP_DNS_CHECK=1` skips it for a hostname behind a
   proxy such as Cloudflare, whose addresses are not the droplet's.
4. After the stack is up, `deploy.sh` waits for HTTPS to answer with a valid
   certificate for the hostname, and if it does not, says where to look.

## The certificate store

Caddy keeps its certificates and ACME account in the `caddy_data` named
volume, mounted at `/data`. A named volume, not a directory in the tree,
because the release is rsync'd with `--delete`, and a store that is wiped gets
a new certificate on every deploy until Let's Encrypt's rate limit (five
duplicate certificates a week) locks the hostname out for days. Never
`docker compose down -v` in production.

## The rules the Caddyfile keeps

Carried over from the nginx configs it replaced (Caddy took over on 2026-10-12), and asserted by
`tests/Unit/CaddyfileTest.php`:

- Security headers (HSTS, nosniff, the content security policy, the referrer
  policy) on every response, error responses included, and deferred so nothing
  upstream can overwrite them.
- `/assets/` is cached for a year as immutable (Vite hashes the filenames);
  everything else, `index.html` and every PHP response included, is
  `no-cache, no-store, must-revalidate`.
- Anything that is not a real file goes to `index.php`, which serves the API
  and the SPA shell.
- No dotfile is ever served.
- Request bodies are capped at 2 MB.
- `/cron` is answered with 404 on the site, and served only on port 8081,
  which production publishes on the server's loopback address alone, to
  loopback and the Docker bridge.

## Failure list

1–6 are tested in `tests/Unit/DeployScriptsTest.php` (the DNS check, with stand-in `dig`,
`hostname` and `curl`), and 7 and 10–16 in `tests/Unit/CaddyfileTest.php`. 8 and 9
are steps in `deploy/deploy.sh`, which only runs on a server; the suite checks
that it parses, and both were exercised by hand against a real Caddy.

1. The hostname has no A record, and Caddy retries a certificate it can never
   get while the site is down.
2. The A record points at some other address.
3. An AAAA record points at an address the droplet does not have, and Let's
   Encrypt, which tries IPv6 first, fails.
4. A reserved IP is in DNS, which is not on any interface, and a correct setup
   is refused.
5. The DNS check reads `/etc/hosts` or a stale local cache instead of what
   Let's Encrypt will see.
6. `dig` is missing and the check passes because it found nothing to compare.
7. The certificate store is wiped by a deploy, and repeated re-issues hit Let's
   Encrypt's rate limit.
8. The Caddyfile has an error, and the deploy takes the site down with it
   instead of stopping first.
9. The deploy reports success while HTTPS is not actually serving a valid
   certificate.
10. Local development tries to get a certificate, or redirects to HTTPS.
11. The hostname is written in more than one place and the copies drift.
12. A response, an error response included, goes out without the security
    headers, or with them overwritten by PHP.
13. `index.html` or a PHP response is cached, and clients pin themselves to a
    dead bundle.
14. A dotfile is served.
15. The 2 MB body cap is lost.
16. `/cron` is reachable through the public site, or the cron port is
    published beyond loopback.
