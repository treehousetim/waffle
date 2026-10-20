# Forms

What a site's forms send, received and kept by the app (docs/content-model.md,
"form_submissions" and "sites"; Waffle Site's docs/forms.md is the site's
half). Part of the `content` slice.

## Sites and their keys

Every site that sends forms is a row in `sites`, made by an owner on the
**Sites** page: a slug, a name, its origin (`https://www.example.com`). The
owner issues the site a **key**, shown once, and puts it in the site server's
Doppler config as `WAFFLE_SITE_KEY`; the site's Caddy adds it to every
`/api/site/*` request as `X-Site-Key`. The app keeps only its SHA-256.
Issuing a new key replaces the old one at once; a disabled site's key opens
nothing.

## How a submission is received

`POST /api/site/forms/<name>`, from the site's server:

1. The `X-Site-Key` must be an active site's key, or the answer is 401.
2. The form must be one the app declares (`form:<name>`), or 404.
3. **The honeypot**: a filled `company_website` is answered exactly like a
   good submission, and nothing is kept.
4. **The rate limit**: at most 5 submissions from one visitor address in 10
   minutes, or 429. The address is never stored: only a SHA-256 of it with
   the date and the site, which changes daily.
5. The form's parser checks every field (`Domain/Forms`); a refusal is 422
   with an `error` and a message per field.
6. The submission is kept as `new`, with the fields as the parser returned
   them, and the answer is the form's thanks message.

A JSON request is answered in JSON. A form-encoded one (a browser without
script) is answered with a `303`: to the `redirect` field's path on success,
to the `back` field's path on failure, each only if it is a plain path on the
site (never a full URL), otherwise to `/`.

## The inbox

The **Inbox** page lists submissions, newest first, by status: `new`,
`handled`, `spam` (`submission_status`). Each is marked handled or spam by
someone with `forms-manage`. Submissions are personal data: the setting
`form_retention_days` (default 180) says how long they are kept, and
`/cron/forms` deletes older ones nightly.

## Declaring a form

A migration inserting its `types` row in the group `form`, and a
`form:<name>` definition in a container fragment: a `FormDefinition` with the
form's label, thanks message and `FormFields` parser. The skeleton declares
`contact` (name, email, message).

## Permissions

| Permission | What it allows | Owner | Administrator | User |
| --- | --- | :---: | :---: | :---: |
| `forms-manage` | Read the inbox; mark submissions handled or spam | ✓ | ✓ | |
| `sites-manage` | Add sites, issue their keys, disable them | ✓ | | |

## API

| Route | Permission | Does |
| --- | --- | --- |
| `POST /api/site/forms/{name}` | the site key | receives a submission |
| `GET /api/forms/submissions?status=` | `forms-manage` | submissions of one status (default `new`), newest first |
| `POST /api/forms/submissions/{id}/handled` | `forms-manage` | |
| `POST /api/forms/submissions/{id}/spam` | `forms-manage` | |
| `GET /api/sites` | `sites-manage` | every site |
| `POST /api/sites` | `sites-manage` | `{slug, name, origin}` |
| `POST /api/sites/{id}/key` | `sites-manage` | a new key, shown this once |
| `POST /api/sites/{id}/disable`, `/enable` | `sites-manage` | |
| `GET /cron/forms` | the cron secret | deletes submissions past retention |

## Failure list

1. A submission without a key, with a wrong key, or with a disabled site's key is kept.
2. A site key is stored as it is, or shown again after it was issued.
3. A filled honeypot is kept, or answered differently from a good submission.
4. One address sends submissions without limit.
5. A visitor's address is stored, or hashed the same way forever.
6. A field the form does not take is kept, or a field it requires is missing, too long, or holds control characters; or an email address is not one.
7. A form the app does not declare accepts submissions.
8. A form-encoded submission is refused as a cross-site form, or answered with JSON.
9. A redirect after a form-encoded submission goes to another site (an open redirect).
10. Someone without `forms-manage` reads the inbox, or without `sites-manage` manages sites or keys.
11. Submissions are kept forever.
12. A submission is marked handled twice, or moved from spam to handled.
13. Two sites share a slug, or a site's origin is not an https origin.
