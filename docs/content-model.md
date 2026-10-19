# The content model

**Status: proposal.** No table here exists yet. This is the shape every app
will use for anything a site publishes (docs/setups.md, setups 2 to 4), so
that agents build it the same way each time and Waffle Site can rely on it.
It follows AGENTS.md §6: every column prefixed with its table's name,
statuses as foreign keys into `types`, money in cents, times in UTC.

The worked example is a vacation-rental business: `properties`, grouped by
town and kind. Replace `properties` with the app's own record type; the rest
of the shape stays.

## Principles

1. **Code owns pages; the database owns records.** A page that exists once
   (home, about, pricing) is an `.astro` file in the site, written and
   reviewed like code. The database holds the records that become many pages
   (properties, towns, services, people) and the text that belongs to them.
   There is no `pages` table and no page builder.
2. **One table per record type**, carrying the publishable columns below and
   its own fields. No generic "entries" table with key/value fields: each type
   gets real columns, real constraints and its own parser on the site.
3. **Publishing is a state machine**, `draft` → `published` → `retired`, in
   `Domain/` (AGENTS.md §4). Publishing checks the publish bar, the same
   minimums the site's build enforces (Waffle Site's docs/data-pages.md), so
   staff see why a record cannot go live before the site refuses it.
4. **A slug is forever.** Every slug a record has had is kept; none is ever
   given to another record. The site turns old slugs into redirects.
5. **Media is described when it is uploaded**: alt text, credit and licence
   are fields, required before a record using the image can publish.
6. **Changes are told, not polled.** A change to published content queues a
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
| `<t>_body` | MEDIUMTEXT | the record's main text, as Markdown without raw HTML; the site renders it |
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

Originals are kept by the app (a volume, or object storage) and handed to
the site's build by URL; the site resizes them and serves its own copies.
Nothing on the public site links to the app's storage.

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
| `GET /api/site/properties` | every published property: the publishable columns, its fields, its terms by slug, its media (URL, size, alt, credit), its previous slugs. Paged by path once large |
| `GET /api/site/properties/retired` | retired slugs, each with its redirect hub or none |
| `GET /api/site/terms/<taxonomy>` | the published terms of one taxonomy, with intros and previous slugs |
| `POST /api/site/forms/<form>` | a form submission, parsed by that form's parser |
| live reads (`/api/site/availability`...) | live data, never cached into pages |

Each answers in the site API envelope and is read by a strict parser on the
site (Waffle Site's docs/waffle-api.md).

## What is deliberately not here

- **A page builder or a `pages` table.** Fixed pages are code (principle 1).
- **Generic custom fields.** A new field is a migration and a column.
- **Revisions.** A record's history before the current version is not kept
  in the first version of this model; add `<t>_revisions` (a JSON snapshot
  per publish) when an app needs to undo or audit edits.
- **Comments or user-generated content.** A feature of its own when needed.

## Building it

This becomes a slice per piece (AGENTS.md, docs/adding-a-feature.md): the
publish state machine and slugs first, then terms, media, forms and sites,
each with its failure list and tests. The queue it relies on is in the skeleton
(docs/queue.md); whether the content model joins it there or ships as a
Waffle Iron module is still to decide.
