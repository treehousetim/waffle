# Resolved questions

Questions moved out of `OPEN-QUESTIONS.md` once answered, kept for the reasoning.
Each starts with where it was settled. Newest moves at the top.

---

## When is v1?

**Resolved 2026-10-19 by the owner:** after a real project built with Waffle,
Waffle Iron and Waffle Site has run on 0.x, and what it taught has been
brought back into the templates. v1 promises conventions people have lived
with.

## How do projects take template changes?

**Resolved 2026-10-19 by the owner:** a project owns its code from setup on.
The template's changelog, written for people porting changes, is the guide;
there is no backport or upgrade tool. (A tool that applied patches with the
rename undone was considered and declined.)

## How are Waffle and Waffle Site versioned against each other?

**Resolved 2026-10-19 by the owner:** matching majors. Waffle Site 1.x works
with Waffle 1.x apps, and a breaking change to the site API is a major in
both. docs/setups.md says so.

## How do people join an app?

**Resolved 2026-10-19 by the owner, not yet built:** a closed app joins by
invitation (an owner sends a single-use link; no sign-up form). An open app
registers only through OAuth with Google, Apple and GitHub, never an
email-and-password form. Password accounts reset by an emailed link. Mail is
SMTP through a provider, sent from the queue (docs/queue.md). OAuth needs a
client library that is not on the stack list: it is proposed for approval
when the feature is built (AGENTS.md §2).

## The content model's open choices

**Resolved 2026-10-19 by the owner** (docs/content-model.md): it ships as a
Waffle Iron module, not in the skeleton. Posts are Markdown; pages, and any
record type that needs more than prose, are structured blocks from a fixed
set. The publish bar lives in the app and is sent to the site. Media is in
object storage from day one. Every publish is kept as a revision. The `sites`
table exists from the first site. A new record type is added by an agent
following the document, with no generator.

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
