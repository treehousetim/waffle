# Open questions

Sparse list. One line each where possible. When answered, move it to `RESOLVED_QUESTIONS.md` with a line saying where it was settled.

## Decisions the template made that you may want to change
- **Auth:** email + password, and today only the first owner (`FIRST_USERNAME`/`FIRST_USER_PASSWORD`). How others join is decided (RESOLVED_QUESTIONS.md): invitations are built (docs/invitations.md, the link copied and sent by hand until there is a mailer); open registration through OAuth, emailed reset links and SMTP from the queue are not built yet.
- **Three levels** — owner, administrator, user — and the grid in `docs/access-levels.md`. Rename or add levels before anything is built on them.
- **Display timezone:** the `display_timezone` setting defaults to `UTC`. Set it to where the team is.
- **Seeded dev credentials:** `owner@`, `admin@`, `user@example.test`, password `local-dev-password` unless `SEED_PASSWORD` is set.
- **Caddy's host port** is `${HTTP_PORT:-8080}` and MariaDB's `${DB_HOST_PORT:-11020}` (local only; production publishes no database port).

## Framework decisions to make

- **Waffle Iron modules at 1.0.** Short links declares `waffle >=0.1 <1.0`, so it stops matching at 1.0. Proposed: modules declare a major (`>=1.0 <2.0`), and short links is proven on 1.0 and re-released for it.

- **Media's object-storage client.** Media is built (docs/content.md, "Media") on `LocalDiskStorage`, the `storage_data` volume, as member photos are; the content model wants S3-compatible object storage (RESOLVED_QUESTIONS.md), which is one more `StoragePort` adapter and needs a library. AGENTS.md §2's rule picks the candidate with the most installs: `aws/aws-sdk-php` (large, but the reference client, and works with DigitalOcean Spaces); the lighter alternative is `async-aws/s3`. Until one is approved, back up the volume (below).

## Needs a decision before production
- **Backing up stored files.** The `storage_data` volume (docs/storage.md: uploads, photos) is in no backup. Proposed: the nightly backup also archives the volume beside the database dump, and both go off-site together (below); or files move to object storage, which keeps its own copies.
- **Off-site backups.** `tools/backup-prod-db.sh` keeps 14 nightly dumps on the server itself, which does not survive losing the server. Where should copies go (object storage in another region, with a key limited to one bucket)?
- **Monitoring.** `HEARTBEAT_URL` (a dead-man's switch pinged after each `/cron/health`) is optional. Pick a service, or decide not to.
- **The server.** Region, size and image for the one droplet; see `docs/fresh-install.md`.
