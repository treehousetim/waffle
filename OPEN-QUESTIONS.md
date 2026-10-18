# Open questions

Sparse list. One line each where possible. When answered, move it to `RESOLVED_QUESTIONS.md` with a line saying where it was settled.

## Decisions the template made that you may want to change
- **Auth:** email + password, no open registration, no mailer. The first owner comes from `FIRST_USERNAME`/`FIRST_USER_PASSWORD`; there is not yet a way for anybody else to join. Decide: invitations (a single-use link an owner hands over), owner-created accounts, or SSO.
- **No self-service password reset**, because there is no mailer. Decide whether there will be one.
- **Three levels** — owner, administrator, user — and the grid in `docs/access-levels.md`. Rename or add levels before anything is built on them.
- **Display timezone:** the `display_timezone` setting defaults to `UTC`. Set it to where the team is.
- **Seeded dev credentials:** `owner@`, `admin@`, `user@example.test`, password `local-dev-password` unless `SEED_PASSWORD` is set.
- **Caddy's host port** is `${HTTP_PORT:-8080}` and MariaDB's `${DB_HOST_PORT:-11020}` (local only; production publishes no database port).

## Framework decisions to make

- **A queue, as part of Waffle itself.** Today AGENTS.md §5 says to add a jobs table and worker "when the first slow job arrives", so every app would invent its own. Change notices to a waffle-site (coalesced, retried), mail when there is a mailer, imports, webhooks' follow-up work and reconciliations all need the same thing. Proposal, for a decision:
  - **MariaDB-backed, no new service**: a `jobs` table claimed with `SELECT … FOR UPDATE SKIP LOCKED`, so the stack gains no Redis or broker and backups already cover it.
  - **A worker service** in Compose running the same image (`php tools/worker.php`), long-running with a memory/job-count limit and restart; `/cron/run-jobs` as the fallback drain where a worker cannot run. The deploy pauses it around migrations.
  - **At-least-once delivery, so every job is idempotent**; retries with backoff, a dead state after N failures that is visible in the admin, and one row per attempt (outcome, error) like the integration log.
  - **Coalescing by key**: a job with a `unique_key` that is already pending absorbs the new one (merging its payload, e.g. the slugs of a change notice) and may be delayed (`run_after`), which is the debounce.
  - **Scheduled and delayed jobs** from the same table, so cron routes shrink to "enqueue".
  - **Domain-side port** (`QueuePort`) with a fake for tests; jobs are plain classes with a typed payload, dispatched through the container; the arch suite keeps them out of controllers.
  Decide: this design or another; whether it ships in the skeleton or as a Waffle Iron module (proposal: the skeleton, since change notices and modules depend on it); and the failure list to write first.

## Needs a decision before production
- **Off-site backups.** `tools/backup-prod-db.sh` keeps 14 nightly dumps on the server itself, which does not survive losing the server. Where should copies go (object storage in another region, with a key limited to one bucket)?
- **Monitoring.** `HEARTBEAT_URL` (a dead-man's switch pinged after each `/cron/health`) is optional. Pick a service, or decide not to.
- **The server.** Region, size and image for the one droplet; see `docs/fresh-install.md`.
