# Mail

What the app emails, and how. Built first in KAW Meetup, the first real
project, and brought back. The default provider is [Resend](https://resend.com)
(docs/accounts.md); any other is an adapter behind the same two ports
("Another provider", below), and nothing that sends or files mail changes.

## What it sends

| When | To | What |
| --- | --- | --- |
| A site's form is sent (the contact form) | every enabled owner and administrator: whoever may read the Inbox | the message, with `Reply-To` the sender, so a reply goes straight to them |
| An invitation is made, or sent again | the invited address | the single-use link (docs/invitations.md) |
| Someone asks to reset their password | the account's address | a single-use link, good for an hour (docs/password-reset.md) |
| A password is changed through a reset | the account's address | a notice, so a reset nobody asked for is noticed |

Every email is plain text, from `MAIL_FROM`, with links on `APP_URL`.

## How it works

- **Only the queue's worker sends** (docs/queue.md). A request records what
  happened and enqueues the email in the same transaction, so an email is sent
  exactly when the change it describes is made, and a slow or failing
  provider never slows or fails a request.
- **`MailPort`** is the one way out. In production it is `ResendMailer`
  (`Libraries/Clients/`), Resend's HTTP API through Guzzle. Without
  `RESEND_API_KEY` it is `NoMailer`: on a development machine it writes each
  email to the worker's log (`docker compose logs worker`), links and all; in
  production it refuses, so the job fails and the Queue page shows that mail
  is not going out.
- **An email that carries no secret** (a form, a decision, a notice) is a
  `send-mail` job holding the whole message and a random key made when it was
  queued. Resend is given that key with the email, and drops a repeat of it
  for a day, so a job the queue runs twice sends once.
- **An email that carries a link that opens something** (an invitation, a
  password reset) never puts the link in the database: the job holds only the
  invitation's or the request's id. The worker issues the token when it sends,
  stores its SHA-256, and emails it. Sending again issues a new one, so only
  the newest link works.

## Mail sent to the domain

Resend receives mail for `@waffle.example.com` (its MX record) and tells the app at
`POST /webhooks/resend` (Resend → Webhooks → `email.received`). The app checks
the request's signature (Svix's scheme: an HMAC-SHA256 of
`<svix-id>.<svix-timestamp>.<body>` with the webhook's secret, the timestamp
within five minutes), records the delivery by its `svix-id` so a resent one
does nothing, and queues the email's id. The worker fetches the message
(`GET /emails/receiving/{id}`), files it in the Inbox as an **Email**
submission (from, name, subject, message; no site), and emails everyone who
may read the Inbox, `Reply-To` the sender, as a form does.

## Another provider

Everything that sends mail calls `MailPort::send(Message, $key)`, and the
webhook's job calls `ReceivedMailPort::fetch($id)`; nothing else knows who the
provider is. To use another (SMTP through any mail server, Postmark, SES...):

1. Write the adapter in `src/app/Libraries/Clients/` (network only, thin,
   outside coverage, like `ResendMailer`): it sends the plain-text message,
   and passes `$key` as the provider's idempotency key where it has one, so a
   job run twice sends once (3.). An SMTP library is a new dependency: ask
   for it first (AGENTS.md §2).
2. Bind it to `MailPort` in `src/config/container/mail.php`, under its own
   key in Doppler.
3. For mail sent to the domain, a provider with inbound mail gets its own
   webhook route and signature check, and an adapter for `ReceivedMailPort`;
   the job, the Inbox and the notices stay as they are.

The tests never change: they bind `FakeMailer` and `FakeReceivedMail`.

## Configuration

| Key | Where | What |
| --- | --- | --- |
| `RESEND_API_KEY` | Doppler `prod` | a Resend key with **Sending access** for the domain |
| `MAIL_FROM` | `docker-compose.prod.yml` | the sender, e.g. `Waffle <noreply@waffle.example.com>`; its domain must be verified in Resend |
| `APP_URL` | `docker-compose.prod.yml` | the app's address, for links: `https://waffle.example.com` |
| `RESEND_WEBHOOK_SECRET` | Doppler `prod` | the `email.received` webhook's signing secret (`whsec_…`); empty refuses every delivery |
| `RESEND_RECEIVING_KEY` | Doppler `prod` | a Resend API key that can read received email (Full access: Resend has no read-only key) |

Locally `MAIL_FROM` and `APP_URL` default to a placeholder sender and
`http://localhost:8080`, and nothing is sent.

## Failure list

Each has a test; the numbers are cited beside them.

1. An email is sent inside a request, so a slow or failing provider slows or
   fails it.
2. An email is queued for a change that rolls back, or a change is made and
   its email is lost.
3. A job the queue runs twice sends its email twice.
4. A link that opens something (an invitation, a password reset) is stored as
   it is: in a job's payload, or anywhere else in the database.
5. A subject, name or address carries a line break or a control character
   into a header, or an address is not one.
6. Text a visitor wrote is sent as HTML.
7. The provider refuses or cannot be reached, and the email is lost, or retried
   without end.
8. Production has no key, and mail silently goes nowhere.
9. A form's notice goes to someone who may not read the Inbox, or to an
   account that is turned off; or nobody may read it, and a job fails forever.
10. A visitor makes the app email an address they typed.
11. A link in an email points anywhere but `APP_URL`.
12. An email names something nobody should see: another member's address, a
    password, a site key.
13. A request to the webhook that Resend did not sign, signed with another
    secret, too old, or with no secret configured, is accepted.
14. A delivery Resend sends again is filed twice, or a job run twice files an
    email twice.
15. A site posts the Email form as if it were one of its own.
16. An email with no plain text, only HTML, is filed with its markup, or with
    nothing.
17. The message cannot be fetched (the key is missing or refused, Resend is
    down), and it is lost rather than retried.
