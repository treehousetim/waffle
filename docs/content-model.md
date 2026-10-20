# The content model

**Status: decided 2026-10-19; partly built** (docs/content.md has what
exists: publishing, revisions, slugs, posts, the site API, change notices). It ships
**in the skeleton**, as the backend every derived project starts with (the
tables, publishing, the site API), built on the skeleton's queue
(docs/queue.md). Any editing screens it brings are plain and unstyled: the look
and feel is each project's own. It is being built first in KAW Meetup, the
first real project, as a `content` slice, and brought back from there. This is the shape every app uses for
anything a site publishes (docs/setups.md, setups 2 to 4), so that agents
build it the same way each time and Waffle Site can rely on it. The decisions
are recorded in RESOLVED_QUESTIONS.md.
It follows AGENTS.md §6: every column prefixed with its table's name,
statuses as foreign keys into `types`, money in cents, times in UTC.

The worked example is a vacation-rental business: `properties`, grouped by
town and kind. Replace `properties` with the app's own record type; the rest
of the shape stays.

## Principles

1. **Code owns layout; the database owns content.** The site's templates and
   components are code, written and reviewed in the site's repository. What
   staff edit is content in the database: `pages` and `posts`, which every
   app with the module has, and the app's own record types (properties,
   towns, services, people). A page that never changes without a developer
   (a legal notice, a one-off landing page) may stay an `.astro` file.
2. **Each record type has one body format.** `posts` are Markdown (no raw
   HTML): a blog post is prose. `pages`, and any record type whose pages need
   more than prose, are **blocks**: an ordered list of typed blocks from one
   fixed set (below), each rendered on the site by its own component. A type
   never mixes the two.
3. **One table per record type**, carrying the publishable columns below and
   its own fields. No generic "entries" table with key/value fields: each type
   gets real columns, real constraints and its own parser on the site.
4. **Publishing is a state machine**, `draft` → `published` → `retired`, in
   `Domain/` (AGENTS.md §4). **The publish bar lives in the app**: its
   minimums are app settings per record type, checked when staff publish, so
   they see why a record cannot go live. The site API sends only records that
   passed, and sends the bar's numbers with them, so the site's build checks
   the same numbers instead of keeping its own (Waffle Site's
   docs/data-pages.md, decision 5).
5. **Every publish is kept.** Each publish writes a revision: a snapshot of
   the record's content, who published it and when. Restoring is publishing
   an earlier snapshot again.
6. **A slug is forever.** Every slug a record has had is kept; none is ever
   given to another record. The site turns old slugs into redirects.
7. **Media is described when it is uploaded**: alt text, credit and licence
   are fields, required before a record using the image can publish. Files
   live in **object storage from day one** (S3-compatible: DigitalOcean
   Spaces out of the box), behind a `StoragePort`; development and tests use
   a filesystem adapter.
8. **Changes are told, not polled.** A change to published content queues a
   change notice for the site (AGENTS.md §4), carried by the queue
   (docs/queue.md).

## Tables

### Publishable columns: on every record type

For a record table `<t>` (here `properties`):

| Column | Type | Meaning |
|---|---|---|
| `<t>_id` | INT UNSIGNED, primary key | |
| `<t>_slug` | VARCHAR(100), unique | the current slug: lowercase letters, digits, dashes |
| `<t>_status_id` | FK → `types` (`publish_status`: draft, published, retired) | set only by the state machine |
| `<t>_title` | VARCHAR(150) | the page's `<h1>` and the start of its title |
| `<t>_summary` | VARCHAR(300) | the source of the page's description and of cards that list it |
| `<t>_body` | MEDIUMTEXT or JSON | the record's content, in its type's one format: Markdown without raw HTML (`posts`), or a JSON array of blocks (`pages` and rich types), checked by a parser in `Domain/` on every save |
| `<t>_seo_title` | VARCHAR(60), null | overrides the page title when set |
| `<t>_seo_description` | VARCHAR(160), null | overrides the page description when set |
| `<t>_published_at` | DATETIME, null | first published |
| `<t>_retired_at` | DATETIME, null | |
| `<t>_retired_to_term_id` | FK → `terms`, null | where a retired record's URL redirects (its hub); null answers `410 Gone` |
| `<t>_content_changed_at` | DATETIME | last change to anything the site shows; what change notices are made from |
| `<t>_created_at`, `<t>_updated_at` | DATETIME | |

Then the type's own fields, in real columns: `properties_bedrooms`,
`properties_guests_max`, `properties_from_price_cents`,
`properties_latitude`... Fields that are **live data** (availability, exact
prices for dates) live in their own tables and are never part of the
record's published content.

### Blocks: the content of pages and rich types

A block body is an array of objects, each with a `type` from this fixed set
and that type's fields. The app's parser refuses any other type or field, and
so does the site's; the site renders each type with one component. Adding a
type is a change to both, released together.

| Type | Fields | For |
|---|---|---|
| `heading` | `level` (2-4), `text` | section headings; the page's `<h1>` is its title |
| `text` | `markdown` (paragraphs, lists, links, emphasis; no raw HTML, no headings) | running text |
| `image` | `media_id`, `caption`? | one described image |
| `gallery` | `media_ids` (2-24) | a set of images |
| `quote` | `text`, `cite`? | a quotation, with who said it |
| `callout` | `tone` (note, warning), `markdown` | something the reader must not miss |
| `faq` | `items`: `question`, `answer` (Markdown) | questions and answers; the site adds FAQPage structured data |
| `cta` | `label`, `path` (a page on the same site), `note`? | one call to action |
| `table` | `columns`, `rows` (text cells) | small tabular facts |

Links in blocks point at paths on the site or at `https` URLs, and are
checked by the parser; a block never carries HTML, a script or a style.

### `<t>_revisions`: every publish

| Column | Type | Meaning |
|---|---|---|
| `properties_revisions_id` | INT UNSIGNED, primary key | |
| `properties_revisions_property_id` | FK → `properties` | |
| `properties_revisions_content` | JSON | the record's publishable columns and own fields, as published |
| `properties_revisions_published_by` | FK → `users` | |
| `properties_revisions_published_at` | DATETIME | |

Written in the same transaction as the publish. A restore copies a revision's
content back and publishes it, which writes a new revision.

### `<t>_slugs`: every slug a record has had

| Column | Type | Meaning |
|---|---|---|
| `properties_slugs_id` | INT UNSIGNED, primary key | |
| `properties_slugs_property_id` | FK → `properties` | |
| `properties_slugs_slug` | VARCHAR(100), unique | unique across the type, history included, so no slug is reused |
| `properties_slugs_created_at` | DATETIME | |
| `properties_slugs_replaced_at` | DATETIME, null | null for the current slug |

Changing a slug inserts a row and stamps the old one, in one transaction with
the record's update.

### `terms`: what records are grouped by

Towns, kinds, categories: every grouping that can become a hub page (Waffle
Site's docs/data-pages.md).

| Column | Type | Meaning |
|---|---|---|
| `terms_id` | INT UNSIGNED, primary key | |
| `terms_taxonomy_id` | FK → `types` (`taxonomy`: town, kind...) | which grouping this is |
| `terms_slug` | VARCHAR(100) | unique within its taxonomy |
| `terms_name` | VARCHAR(100) | |
| `terms_parent_id` | FK → `terms`, null | a town within a county, a kind within a kind |
| `terms_intro` | MEDIUMTEXT | the hub page's written introduction, as Markdown; the publish bar requires it |
| `terms_seo_title`, `terms_seo_description` | as above, null | |
| `terms_status_id` | FK → `types` (`publish_status`) | a hub can be drafted and retired too |
| `terms_sort` | SMALLINT UNSIGNED | |
| `terms_created_at`, `terms_updated_at` | DATETIME | |

`terms_slugs` keeps term slug history exactly as `<t>_slugs` does, and
`<t>_terms` joins records to terms:

| Column | Type |
|---|---|
| `properties_terms_property_id` | FK → `properties` |
| `properties_terms_term_id` | FK → `terms` |
| primary key on both | |

### `media`: every image or file, described once

| Column | Type | Meaning |
|---|---|---|
| `media_id` | INT UNSIGNED, primary key | |
| `media_storage_key` | VARCHAR(255), unique | where the original is stored |
| `media_sha256` | CHAR(64), unique | the same file uploaded twice is one row |
| `media_mime` | VARCHAR(100) | |
| `media_width`, `media_height` | INT UNSIGNED, null | images only |
| `media_bytes` | INT UNSIGNED | |
| `media_alt` | VARCHAR(300) | what it shows, for people who cannot see it; required before a record using it publishes |
| `media_caption` | VARCHAR(300), null | |
| `media_credit_name`, `media_credit_url` | VARCHAR, null | who made it, when it is not the business's own |
| `media_licence_id` | FK → `types` (`licence`: own, licensed, unsplash...) | |
| `media_created_at` | DATETIME | |

`<t>_media` attaches media to records, in order and with a role:

| Column | Type |
|---|---|
| `properties_media_property_id` | FK → `properties` |
| `properties_media_media_id` | FK → `media` |
| `properties_media_role_id` | FK → `types` (`media_role`: cover, gallery, plan) |
| `properties_media_sort` | SMALLINT UNSIGNED |

Originals are kept in object storage, behind `StoragePort`, and handed to the
site's build by a short-lived signed URL; the site resizes them and serves
its own copies. Nothing on the public site links to the app's storage. The
storage credentials live in Doppler (`prod-serve`), like every secret.

### `form_submissions`: what the site's forms send

| Column | Type | Meaning |
|---|---|---|
| `form_submissions_id` | INT UNSIGNED, primary key | |
| `form_submissions_form_id` | FK → `types` (`form`: quote, booking...) | |
| `form_submissions_site_id` | FK → `sites` | which site it came from |
| `form_submissions_payload` | JSON | the fields, after the form's own parser in `Domain/` accepted them |
| `form_submissions_status_id` | FK → `types` (`submission_status`: new, handled, spam) | |
| `form_submissions_ip_hash` | CHAR(64) | a salted hash, for rate limits; the address itself is never stored |
| `form_submissions_created_at` | DATETIME | |

Submissions are personal data: a setting says how many days they are kept,
and a cron job deletes older ones.

### `sites`: who publishes

| Column | Type | Meaning |
|---|---|---|
| `sites_id` | INT UNSIGNED, primary key | |
| `sites_slug` | VARCHAR(50), unique | |
| `sites_name` | VARCHAR(100) | |
| `sites_origin` | VARCHAR(255) | `https://www.example.com`: CORS for setup 3, links in the admin |
| `sites_key_hash` | CHAR(64) | the SHA-256 of the site key; the key itself is only in Doppler |
| `sites_status_id` | FK → `types` (`site_status`: active, disabled) | |

Every app with a site has this table, with one row in setups 2 and 3, so
that becoming setup 4 is adding rows, not changing tables.

### Several sites (setup 4)

Each record type that sites share gets a join table saying which sites
publish it (`properties_sites`: `properties_sites_property_id`,
`properties_sites_site_id`), and `terms_sites` does the same for hubs. The
site API answers each site key with only its own rows.

## The site API this model serves

| Endpoint | Returns |
|---|---|
| `GET /api/site/pages`, `GET /api/site/posts` | every published page (blocks) and post (Markdown), as below |
| `GET /api/site/properties` | every published property: the publishable columns, its fields, its terms by slug, its media (URL, size, alt, credit), its previous slugs, and the publish bar it passed. Paged by path once large |
| `GET /api/site/properties/retired` | retired slugs, each with its redirect hub or none |
| `GET /api/site/terms/<taxonomy>` | the published terms of one taxonomy, with intros and previous slugs |
| `POST /api/site/forms/<form>` | a form submission, parsed by that form's parser |
| live reads (`/api/site/availability`...) | live data, never cached into pages |

Each answers in the site API envelope and is read by a strict parser on the
site (Waffle Site's docs/waffle-api.md).

## What is deliberately not here

- **A page builder with free layout.** Pages are blocks from a fixed set,
  laid out by the site's code (principle 1); staff choose content, not
  positions, colours or fonts.
- **Generic custom fields.** A new field is a migration and a column.
- **Drafts' history.** Revisions are kept per publish, not per save.
- **Comments or user-generated content.** A feature of its own when needed.

## Building it

A slice of the skeleton (`content`), built first in KAW Meetup and brought
back, after the queue (docs/queue.md), piece by piece: the publish state machine, revisions
and slugs first; then blocks and their parser; terms; media and
`StoragePort`; form submissions; sites. Each starts with its failure list and
tests. A new record type is added by an agent following this document; there
is no generator.
