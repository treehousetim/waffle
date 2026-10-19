# Resolved questions

Questions moved out of `OPEN-QUESTIONS.md` once answered, kept for the reasoning.
Each starts with where it was settled. Newest moves at the top.

---

## A queue, as part of Waffle itself?

**Resolved 2026-10-18 by the owner:** yes, in the skeleton (not a Waffle Iron
module), because change notices, mail and modules will all assume one. Stored
in MariaDB and claimed with `SKIP LOCKED`, behind `QueuePort`, so jobs are
created in the same transaction as the change they describe and the stack
gains no service. RabbitMQ (through `php-amqplib`) was weighed: it would need
this table as an outbox anyway, and is kept for later as an adapter behind the
same port, when jobs run in the thousands per second or other services consume
the same events. The design and failure list are in docs/queue.md.

## Where do secrets live?

**Resolved by the template:** Doppler, and only Doppler — local and production —
with two configs, named for who reads them, that inherit nothing from each
other: `prod-serve` (the server) and `prod-deploy` (GitHub Actions). Until
2026-10-12 there were three (`dev`, `prd`, `prod-deploy`); `dev` held nothing
the compose defaults did not, so it went, along with `doppler.yaml`, and `prd`
was renamed. A `dev` comes back when a feature first needs a local key. There is no `.env` file anywhere. Tests read the committed
`.env.testing`, which holds no secrets. AGENTS.md §8.

## How is the app deployed?

**Resolved by the template:** GitHub Actions on `release: published`, gated by a
`v*` tag policy on the `prod` environment, builds the frontend and `vendor/` on
the runner and rsyncs one tested tree to the server, where `deploy/deploy.sh`
brings it into service. Nothing is cloned on the server. AGENTS.md §9.
