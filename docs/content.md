# Content

What the app publishes for a Waffle Site to build pages from: the `content`
slice, the built part of docs/content-model.md. Built first in KAW Meetup, the
first real project, and brought back. An app with no site (setup 1) can
ignore it: nothing is published unless someone publishes it.

**Built so far:** publishing (the state machine, revisions, slugs), the first
record type, `posts`, the site API that reads them, and change notices.
**Still to come:** blocks and `pages`; terms; media; sites and form
submissions.
docs/content-model.md is the design for all of it.

## How it works

- **A record type** is one table and its two history tables (`posts`,
  `posts_revisions`, `posts_slugs`), declared once in code as a `RecordType`
  (`src/config/container/content.php`). Everything below works on any record
  type; a type's own fields come from its `RecordFields`. The request names a
  type only by a name the registry knows: no table or column name ever comes
  from a request.
- **The row is the working copy.** Staff save it as often as they like; what
  the site gets is the record's latest **revision**, written by each publish.
  Editing a published record changes nothing on the site until it is published
  again.
- **Statuses** (`App\Domain\Content\PublishStatus`, the `types` group
  `publish_status`):

  ```
  draft → published
  published → retired
  retired → published          (brought back)
  ```

  Publishing a record that is already published writes a new revision and
  keeps it published.
- **The publish bar**: a record cannot be published without a title, a
  summary and a body. Drafts may be incomplete.
- **Every publish is kept.** Restoring a revision copies its content (not its
  slug) into the working copy and publishes it, which writes a new revision.
- **A slug is forever.** Every slug a record has had is kept in `<t>_slugs`
  and is never given to another record; a record may take back one of its own
  old slugs. A new record without a slug gets one made from its title
  (`summer-social`, then `summer-social-2` if that was ever taken).
- **Text**: titles at most 150 characters, summaries 300, SEO titles 60, SEO
  descriptions 160, slugs 100 (lowercase letters, digits, single dashes).
  Bodies of posts are Markdown, at most 60,000 characters, with no raw HTML
  and no top-level heading (the title is the page's `<h1>`: sections start at
  `##`); links go to a path on the site (`/events`), an anchor, `https://` or
  `mailto:`.

## Permissions

| Permission | What it allows | Owner | Administrator | User |
| --- | --- | :---: | :---: | :---: |
| `content-edit` | List, read, create and edit records; see their revisions | ✓ | ✓ | |
| `content-publish` | Publish, retire, and restore a revision | ✓ | ✓ | |

## API

`{type}` is a record type's name (`posts`).

| Route | Permission | Does |
| --- | --- | --- |
| `GET /api/content/{type}?status=` | `content-edit` | the working copies, newest change first; all statuses, or one |
| `POST /api/content/{type}` | `content-edit` | `{title, slug?, summary, body, seo_title, seo_description, ...fields}`: a new draft |
| `GET /api/content/{type}/{id}` | `content-edit` | the working copy, its revisions and its slug history |
| `PUT /api/content/{type}/{id}` | `content-edit` | the same body as create, `slug` required: replaces the working copy |
| `POST /api/content/{type}/{id}/publish` | `content-publish` | checks the bar, writes a revision |
| `POST /api/content/{type}/{id}/retire` | `content-publish` | |
| `POST /api/content/{type}/{id}/revisions/{revision}/restore` | `content-publish` | copies a revision back and publishes it |

## Site API

What a Waffle Site build reads to make pages (Waffle Site's
docs/waffle-api.md). **Public and read-only**, as that contract has it: it
carries only what the site will show to everybody anyway, so a build on a
GitHub runner needs no key. Each declared record type gets its own two paths;
there is no catch-all.

| Route | Answer |
| --- | --- |
| `GET /api/site/{type}` | `{records: [...]}`: every published record as last published (its latest revision, never the working copy), oldest first; each with `id`, `slug`, `title`, `summary`, `body`, `seo_title`, `seo_description`, its own fields, `published_at` (first publish), `updated_at` (this revision), both UTC as `2026-11-12T18:30:00Z`, and `previous_slugs` (to redirect to `slug`) |
| `GET /api/site/{type}/retired` | `{slugs: [...]}`: every slug of every retired record, which the site answers with 410 Gone |

No `/api/site/` request starts a session or sets a cookie: the guards on the
app's own routes are built only when one of those routes runs.

The `sites` table, and with it the site key, comes with form submissions,
which are the first site API calls that write.

## Change notices

Every publish, retire and restore queues a **change notice** in the same
transaction (docs/queue.md): the record type and the slugs whose pages
changed. A publish names the record's slug, and the slug it was last
published under when that differs (that page becomes a redirect); a retire
names every slug the record ever had (each now answers 410). Saving a draft
sends nothing. The job (`site-change-notice`) runs a minute later, so changes
made close together reach the site as one notice: a pending notice absorbs
newer ones, slugs unioned by type.

The worker sends it to the site as a GitHub `repository_dispatch` event of
type `change-notice` on the site's repository, with the changes as
`client_payload.changes` (`{"events": ["agents-night"]}`). The site's
workflow rebuilds only the pages those slugs touch (Waffle Site's
docs/data-pages.md). Two settings:

| Variable | Where | What |
| --- | --- | --- |
| `SITE_REPOSITORY` | `docker-compose.prod.yml`, as a literal | the site's repository, `owner/name` (not a secret) |
| `SITE_DISPATCH_TOKEN` | Doppler, `prod-serve` | a fine-grained token with the Contents permission (read and write) on that repository only |

Without both, a notice is not sent: silently outside production, and as a
failed job in production, so the Queue page shows it. `SITE_REPOSITORY` ships
empty in `docker-compose.prod.yml`; a project with a site writes its own there.

**Adding a record type.** A migration making `<t>`, `<t>_revisions` and
`<t>_slugs` exactly as `posts` has them, plus the type's own columns; a
`record_type:<t>` definition in the slice's container fragment, with a
`RecordFields` for its own columns (parse, present, what publishing needs);
its page in the frontend router (`ContentView` with its `fields`). The
routes, the site API and change notices follow from the declaration. KAW
Meetup's `events` is the worked example.

## Failure list

1. A record is published without a title, a summary or a body.
2. A status moves along an edge the machine does not allow (retiring a draft, retiring twice).
3. Editing a published record changes what the site gets before it is published again.
4. A publish leaves the record published without a revision, or a revision without the publish (they are written in one transaction).
5. Restoring a revision overwrites the working copy without a new revision, changes the slug, or takes a revision of another record.
6. A slug that another record has, or ever had, is given to this one.
7. Changing a slug loses the old one, or leaves the record with two current slugs.
8. A slug that is not lowercase letters, digits and single dashes, or is longer than 100 characters, is accepted.
9. A body holding raw HTML, or a link to `javascript:`, `data:`, `http:` or another scheme, is accepted.
10. A title, summary or SEO text that is too long, empty where required, or holds control characters, is accepted.
11. Republishing a retired record loses when it was first published, or keeps it marked retired.
12. Someone without `content-edit` reads or edits records, or without `content-publish` publishes, retires or restores.
13. A record type, record or revision that does not exist, or an id that is not a number, gives a 500 instead of a 404.
14. Two people publish the same record at once, and the revisions do not match the order the publishes took effect.
15. A table or column name in SQL comes from the request instead of a declared record type.
16. The site API hands out a draft, a retired record, or a working copy that has not been published.
17. A site API request starts a session or sets a cookie.
18. The site API answers for a table that is not a declared record type.
19. A change to what the site shows queues no notice, queues one without the change (or the change without one), or names a slug the change did not touch.
20. Several changes close together send several notices, and the site runs overlapping builds.
21. Without a site configured, production loses notices silently.
