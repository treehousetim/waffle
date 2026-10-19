# The queue

Anything that must not run inside a user's request goes through the queue:
change notices to a waffle-site, mail once there is a mailer, imports, work
that follows a webhook, reconciliations. A request records what is wanted,
enqueues a job, and returns; the `worker` service runs it.

Decided 2026-10-18 (RESOLVED_QUESTIONS.md): part of the skeleton, kept in
MariaDB, behind `QueuePort`. Built 2026-10-19.

## Terms

| Term | Meaning |
|---|---|
| **job** | One unit of work: a class implementing `App\Domain\Queue\Job`, with a typed payload. |
| **handler** | The class that runs one type of job: `App\Domain\Queue\JobHandler`, registered under the container key `job:<type>`. |
| **enqueue** | Record a job to run later, through `QueuePort`. |
| **worker** | The long-running process that claims and runs jobs: the `worker` Compose service, running `src/cli/worker.php`. |
| **claim** | A worker taking a job so no other runs it: a row lock with `SKIP LOCKED`, then a lease. |
| **lease** | How long a claim holds (`QueueSettings::leaseSeconds`). A job whose lease runs out unfinished is claimed again. |
| **unique key** | A job's coalescing key. A pending job with the same key absorbs a new one (`Job::merge`) instead of a second row. |
| **dead** | A job that failed its last attempt, or could not be run at all. It waits on the Queue page for a person to retry or discard it. |

## Adding a job

1. **The job**: a class in your slice's `Domain/` implementing `Job`:
   `type()` (its name, e.g. `site-change-notice`), `payload()` (plain JSON,
   only what the work needs: no secrets), `fromPayload()` (rebuilds it, and
   throws `InvalidJobPayload` on anything it did not write), and `merge()`
   (what coalescing does: most return the newer job; a change notice returns
   the union of slugs).
2. **The handler**: a class implementing `JobHandler`: `jobClass()` and
   `handle()`. **`handle()` must be idempotent**: the queue runs a job at
   least once, and sometimes twice. Its tests run it twice.
3. **A migration** inserting the job's `types` row: group `job`, name =
   `type()`. `VocabularyTest` fails until the row and the handler agree.
4. **Register the handler** in the slice's container fragment under
   `job:<type>`. That is all: `container.php` gathers every `job:` key.
5. **Enqueue** through `QueuePort` (inject it), inside the same transaction
   as the change the job is about:
   ```php
   $this->queue->enqueue(new SiteChangeNotice('properties', [$slug]), uniqueKey: 'site-notice');
   ```
   Tests of the code that enqueues get `FakeQueue`, which records the jobs
   (`tests/Support/Overrides/queue.php`); the handler is tested on its own.

## How it works

### The port and the tables

- `QueuePort::enqueue(Job, ?uniqueKey, ?runAfter)`. The adapter,
  `App\Libraries\Queue\DatabaseQueue`, writes through the application's own
  connection, so a job enqueued inside a transaction exists exactly when the
  change it describes does.
- **`jobs`**: type and status as foreign keys into `types` (`job`,
  `job_status`), the JSON payload, the unique key and `jobs_pending_key` (the
  key while pending, NULL once claimed, under a unique index: one pending job
  per key), `run_after`, attempts and their maximum, the worker and lease
  holding it, the last error, created and finished times. UTC throughout.
- **`job_runs`**: one row per attempt: worker, attempt number, outcome
  (`job_run_outcome`: done, failed, dead, abandoned), error, start and end.

### Enqueueing with a unique key

The job is inserted first; the unique pending key decides, in the database,
whether a pending job already holds the key. If one does, the insert fails
with a duplicate key, which also leaves a shared lock on that row, so no
worker can claim it before the merge commits; the pending job is then
locked and its payload replaced with `Job::merge` of the two. The earlier
job's `run_after` stands, so a stream of changes cannot postpone it forever.
A job already running is never merged into: the new one waits behind it.

### Running jobs

- **Claim** (`JobRepository::claim`, at READ COMMITTED so no gap locks hold
  other workers up): first running jobs whose lease ran out (their worker
  died: the attempt is recorded as abandoned, and the job is dead if it was
  the last), then due pending jobs, oldest first, each query walking an index
  so it locks only what it takes, `FOR UPDATE SKIP LOCKED`. Each claim counts
  as an attempt.
- **Run**: the handler is resolved from the registry and the job rebuilt from
  its payload; an unknown type or an unreadable payload is dead at once.
  Success is done. A throw retries after
  `backoff × 2^(attempt−1)`, capped, plus jitter up to one base delay; on
  the last attempt it is dead.
- **The worker** runs batches until it has run 1,000 jobs or used 128 MB,
  then exits and Compose starts a fresh one. A stop signal (SIGTERM, SIGINT,
  or SIGQUIT, the php:fpm image's own) lets the job in hand finish, gives
  back the claimed jobs it had not started, and exits. A lost database
  connection exits non-zero after five seconds, and Compose restarts it.
- **Deploys** scale the worker to zero before migrations and start it after
  php restarts (`deploy/deploy.sh`).

### The policy

`App\Domain\Queue\QueueSettings`, with these defaults; change them in
`src/config/container/queue.php`:

| Setting | Default |
|---|---|
| lease | 300 s |
| attempts | 5 |
| first retry delay, doubling | 30 s |
| longest retry delay | 3,600 s |
| a due job waiting longer than this means the worker stopped | 900 s |
| finished jobs kept | 14 days |
| jobs claimed at once | 10 |

### Seeing it

- **`/cron/queue`**, every five minutes after `/cron/health`
  (`deploy/crontab`): deletes finished jobs past their keeping time, and
  answers 503 when a due job has waited past the stall threshold. The
  heartbeat is pinged only when both answer 200, so a stopped worker stops the
  heartbeat and the outside service alerts.
- **The Queue page** (`/queue`, permission `queue-manage`: owners and
  administrators) and `GET /api/queue`: counts waiting, running and failed,
  how long the oldest due job has waited, and every dead job with each
  attempt's error, to retry (`POST /api/queue/jobs/{id}/retry`) or discard
  (`DELETE /api/queue/jobs/{id}`).

### When to move to a broker

The adapter changes, not the code that uses the port. Move to RabbitMQ
(through `php-amqplib`, behind `QueuePort`, fed from this table as its
outbox) only when one of these is true and measured: jobs regularly arrive in
the thousands per second, or other services, not this app, consume the same
events.

## Failure list

Each has its test; the file is named after the number.

1. A job is enqueued but the change that called for it rolls back, and the job runs for something that did not happen. → `Schema/QueueTransactionTest`
2. A change commits but its job is lost. → `Schema/QueueTransactionTest`
3. Two workers claim and run the same job at the same time. → `Schema/QueueTransactionTest`, `Unit/Models/JobRepositoryTest`
4. A worker dies mid-job, and the job stays "running" forever. → `Unit/Models/JobRepositoryTest`
5. A job runs twice (a crash after the work, a lease expiring mid-run) and does its work twice. → each handler's own test runs it twice
6. A job that always fails retries forever. → `Unit/Libraries/Queue/WorkerTest`
7. Retries hammer a failing service with no backoff. → `WorkerTest`, `Unit/Domain/Queue/QueueSettingsTest`
8. Two requests enqueue the same unique key at once, and two pending jobs result, or one change is lost in the merge. → `DatabaseQueueTest`, `JobRepositoryTest`
9. A new job is merged into one already running, and its change is never run. → `DatabaseQueueTest`, `JobRepositoryTest`
10. A deploy changes a job's payload shape, and jobs already queued cannot be read. → `WorkerTest`, `DatabaseQueueTest`
11. A job type is removed or renamed while jobs of it are pending. → `WorkerTest`, `DatabaseQueueTest`, `JobRegistryTest`, `VocabularyTest`
12. Migrations run while the worker runs jobs against the schema they are changing. → `Unit/WorkerServiceTest`
13. The worker leaks memory, or keeps a dead database connection after MariaDB restarts. → `WorkerTest`, `WorkerServiceTest`
14. A deploy's stop signal kills the worker mid-job. → `WorkerTest`, `WorkerServiceTest`
15. Finished jobs are never deleted, and the table grows without end. → `JobRepositoryTest`, `Feature/QueueTest`
16. The worker stops and nobody notices while jobs pile up. → `Feature/QueueTest`, `Unit/DeployScriptsTest`
17. A delayed job runs early or never, from comparing local time with UTC. → `JobRepositoryTest`
18. A controller runs a job's slow work in the request instead of enqueueing it. → `Arch/ArchTest`
19. A payload carries secrets or more personal data than the job needs, and keeps it in the table. → review: `Job::payload()` says so, and finished jobs are deleted after 14 days
20. Dead jobs are invisible, or nobody may retry or discard them. → `Feature/QueueTest`, `Feature/E2E/QueueJourneyTest`
21. Tests depend on a running worker and become slow or flaky. → `FakeQueue`; every queue test runs the worker's code in-process
