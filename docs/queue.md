# The queue

**Status: designed, not built.** Decided 2026-10-18: the queue is part of the
skeleton, stored in MariaDB, behind `QueuePort` (RESOLVED_QUESTIONS.md). This
document is the design and the failure list; the tests are written from the
failure list before any code (AGENTS.md §7, docs/adding-a-feature.md).

Anything that must not run inside a user's request goes through the queue:
change notices to a waffle-site, mail once there is a mailer, imports,
work that follows a webhook, reconciliations. A request records what is
wanted, enqueues it, and returns.

## Terms

| Term | Meaning |
|---|---|
| **job** | One unit of work: a class with a typed payload and a handler. |
| **enqueue** | Record a job to run later, through `QueuePort`. |
| **worker** | The long-running process that claims and runs jobs: its own Compose service, the same image as the app. |
| **claim** | A worker taking a job, so no other worker runs it: a row lock, then a lease. |
| **lease** | How long a claim holds. A job whose lease runs out without finishing is claimed again. |
| **unique key** | A job's coalescing key. A pending job with the same key absorbs a new one instead of a second row. |
| **dead** | A job that failed its last allowed attempt. It waits in the admin for a person to retry or discard it. |

## The design

### The port

```php
// Domain/Ports/QueuePort.php
interface QueuePort
{
    /**
     * Run $job later. When a pending job has the same unique key, the two
     * are merged into it (Job::merge) instead of adding a row.
     */
    public function enqueue(Job $job, ?string $uniqueKey = null, ?DateTimeImmutable $runAfter = null): void;
}
```

- A **job** is a class in `Domain/` implementing `Job`: `payload(): array`,
  `static fromPayload(array): static`, and `merge(Job): Job` for coalescing.
  Its **handler** is a separate class, resolved through the container, that
  does the work. The payload is plain JSON from typed fields, never PHP
  `serialize()`.
- **The adapter**, `Libraries/Queue/DatabaseQueue.php`, writes through the
  same PDO connection as the code that called it, so an enqueue inside a
  transaction commits or rolls back with the change it describes.
- **Tests use `FakeQueue`**, which records what was enqueued and can run it,
  so a test asserts "publishing sent a change notice for lakeside-cabin"
  without a worker.

### The tables

Following AGENTS.md §6 (table-prefixed columns, statuses in `types`, UTC):

**`jobs`**

| Column | Type | Meaning |
|---|---|---|
| `jobs_id` | BIGINT UNSIGNED, primary key | |
| `jobs_type_id` | FK → `types` (`job`) | which job; each job class adds its row by migration |
| `jobs_payload` | JSON | |
| `jobs_unique_key` | VARCHAR(191), null | the coalescing key |
| `jobs_pending_key` | generated: the unique key while pending, else null; unique index | at most one pending job per key; running and finished ones do not block a new one |
| `jobs_status_id` | FK → `types` (`job_status`: pending, running, done, dead) | |
| `jobs_run_after` | DATETIME | not before this |
| `jobs_attempts` | TINYINT UNSIGNED | |
| `jobs_max_attempts` | TINYINT UNSIGNED | default 5 |
| `jobs_locked_by` | VARCHAR(100), null | the worker holding it |
| `jobs_lease_until` | DATETIME, null | |
| `jobs_last_error` | TEXT, null | |
| `jobs_created_at`, `jobs_finished_at` | DATETIME | |

**`job_runs`**: one row per run (job, worker, started, finished,
outcome, error, duration), kept like the integration log so a failing job's
history is visible.

### Running jobs

- **Claim:** in one transaction, `SELECT … WHERE status = pending AND
  run_after <= UTC_TIMESTAMP() ORDER BY run_after, id LIMIT n FOR UPDATE SKIP
  LOCKED`, then mark them running with a lease. Two workers never take the
  same row.
- **Success:** status done, `finished_at` set. **Failure:** the attempt is
  logged; with attempts left the job goes back to pending with
  `run_after` = now + backoff (exponential, with jitter, capped); without, it
  is dead.
- **At least once.** A job can run twice (a worker dies after the work and
  before recording it; a lease expires mid-run). **Every handler is
  idempotent**, and its tests run it twice.
- **Coalescing:** enqueue with a unique key locks the pending row with that
  key, if any, and merges the new job into it; otherwise inserts. A job
  already running is never merged into: the new one becomes a new pending
  row.

### The worker

- `docker-compose.yml` gains a `worker` service: the app's image, running the
  worker command, `restart: unless-stopped`.
- It exits on its own after a set number of jobs or amount of memory, and
  Compose starts it again: a long-running PHP process stays clean.
- On `SIGTERM` it finishes the job in hand, then exits; it never starts one
  it cannot finish within the stop timeout.
- It reconnects to MariaDB after a lost connection, and pings it between
  jobs.
- **Deploys** stop the worker before migrations and start it after the
  restart (`deploy/deploy.sh`), so no job runs against a schema mid-change.

### Scheduled work

Cron stays the clock: a `/cron/<job>` route (behind `CronMiddleware`)
enqueues, and the worker does the work. Delayed jobs use `run_after`.

### Seeing it

- `/cron/health` reports the oldest pending job's age and the number of dead
  jobs; past a threshold, the health check fails, so the heartbeat stops.
- An admin page (its own permission) lists dead jobs with their attempts,
  and retries or discards them.
- Finished jobs are deleted after a set number of days (a setting); dead ones
  stay until a person acts.

### When to move to a broker

The adapter changes, not the code that uses the port. Move to RabbitMQ
(through `php-amqplib`, behind `QueuePort`, fed from this table as its
outbox) only when one of these is true and measured:

- jobs regularly arrive in the thousands per second, or
- other services, not this app, consume the same events.

## Failure list

Each gets a test before the code that prevents it.

1. A job is enqueued but the change that called for it rolls back, and the job runs for something that did not happen.
2. A change commits but its job is lost.
3. Two workers claim and run the same job at the same time.
4. A worker dies mid-job, and the job stays "running" forever.
5. A job runs twice (a crash after the work, a lease expiring mid-run) and does its work twice.
6. A job that always fails retries forever.
7. Retries hammer a failing service with no backoff.
8. Two requests enqueue the same unique key at once, and two pending jobs result, or one change is lost in the merge.
9. A new job is merged into one already running, and its change is never run.
10. A deploy changes a job's payload shape, and jobs already queued cannot be read.
11. A job type is removed or renamed while jobs of it are pending.
12. Migrations run while the worker runs jobs against the schema they are changing.
13. The worker leaks memory, or keeps a dead database connection after MariaDB restarts.
14. A deploy's `SIGTERM` kills the worker mid-job.
15. Finished jobs are never deleted, and the table grows without end.
16. The worker stops and nobody notices while jobs pile up.
17. A delayed job runs early or never, from comparing local time with UTC.
18. A controller runs a job's slow work in the request instead of enqueueing it.
19. A payload carries secrets or more personal data than the job needs, and keeps it in the table.
20. Dead jobs are invisible, or nobody may retry or discard them.
21. Tests depend on a running worker and become slow or flaky.
