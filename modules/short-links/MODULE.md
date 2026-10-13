---
name: short-links
title: Short links
version: 1.0.0
summary: Your own short links (yoursite.com/go/spring-sale) that redirect where you point them, with a daily click count and nothing tracked about the people who click.
waffle: >=0.1 <1.0
proven-on: none
author: treehousetim
license: MIT
permissions: short-links-view, short-links-edit
libraries: none
config: none
---

# Short links

## Purpose

Short, memorable links on the site's own domain, such as
`https://yoursite.com/go/spring-sale`, that redirect to wherever the owner points
them: a long product page, a booking form, a PDF, another site. They are for
campaigns, QR codes, printed flyers and social bios, where a link must be
short, must keep working, and may need to point somewhere new later without
reprinting anything.

Each link counts how many times it is followed, per day. It counts nothing
else: no IP address, no cookie, no browser fingerprint. A link can be paused
(it stops redirecting until resumed) and can expire on a date.

It is the system of record for the links, where they point, and their daily
click counts.

## Roles and permissions

Two permissions:

| Permission | Lets you |
| --- | --- |
| `short-links-view` | see the links, where they point and their click counts |
| `short-links-edit` | create links, change where they point and their labels and expiry, pause and resume them, delete them |

| Level | May |
| --- | --- |
| **owner** | everything (the owner holds every permission) |
| **administrator** | `short-links-view` and `short-links-edit` |
| **user** | `short-links-view`: sees links and counts, changes nothing |
| **not signed in** | follow a link (`GET /go/{code}`) and nothing else |

Add both as `Permission` cases (`ShortLinksView`, `ShortLinksEdit`), give the
administrator both and the user `ShortLinksView` in `Permissions::GRID`, list
every `/api/short-links` route in `tests/Support/access-routes.php`, and add
them to `docs/access-levels.md`. The page and every create, edit, pause and
delete control are hidden from a level without the permission they need.

## Behaviour

**Codes.** The part after `/go/`. Lowercase letters, digits and single
hyphens, 1 to 64 characters, starting and ending with a letter or digit
(`spring-sale`, `qr7`). Input is lowercased before it is checked, so
`Spring-Sale` is stored as `spring-sale`, and following `/go/SPRING-SALE` works
too. A code left blank is generated: six characters from
`abcdefghjkmnpqrstuvwxyz23456789` (no `0`, `o`, `1`, `l` or `i`, which are
misread on paper), drawn with `random_int`, retried on the rare collision.
**A code never changes once created**: it may already be printed. To use
another code, create another link.

**Targets.** Where a link points. An absolute `http://` or `https://` URL with
a host, at most 2048 characters, with no whitespace or control characters and
no `user:password@` part (which makes `https://yourbank.com@elsewhere.example`
look like the bank). A target on the site's own host under `/go/` is refused,
since a link to a link is a loop. The target can be changed at any time.

**Statuses.** A backed enum, `ShortLinkStatus`, with cases `active` and
`paused`, using Waffle's `HasTransitions`: `active → paused` and
`paused → active`, nothing else. Its rows live in `types` under the group
`short_link_status`, and `tests/Unit/Domain/VocabularyTest.php` gets the pair.

**Following a link.** `GET /go/{code}`:

- an active link whose expiry, if any, is still in the future answers **302**
  to its target, with `Cache-Control: no-store` (so a browser never remembers
  the redirect and a paused or retargeted link takes effect at once) and
  `Referrer-Policy: no-referrer`;
- an unknown code, a paused link and an expired link all answer the same
  plain **404**, so nobody can tell a paused link from one that never
  existed;
- a `HEAD` request is answered the same way but **not counted**, and neither
  is a request carrying `Sec-Purpose: prefetch` or `Purpose: prefetch`
  (browsers and chat apps fetching ahead of a click);
- every other followed link adds one to that link's count for the day.

**Counting.** One row per link per day, incremented with
`INSERT ... ON DUPLICATE KEY UPDATE short_link_clicks_count = short_link_clicks_count + 1`,
so two clicks at the same moment are both counted. The day is the date in
the site's `display_timezone` setting at the moment of the click (UTC if that
setting is not a valid zone), taken from `ClockPort`, so "today" on the
chart is the owner's today.

**Expiry.** Optional, stored in UTC, entered and shown in the display
timezone. An expired link stays in the list (marked expired) with its counts;
moving the expiry later revives it.

**Deleting** a link deletes its counts with it. Its code becomes free again.

## Data model and migrations

Two tables and two lookup rows, following AGENTS.md §6: every column prefixed
with its table's name, UTC timestamps, the status a foreign key into `types`.

| Table | Holds |
| --- | --- |
| `short_links` | one row per link: code, target, label, status, expiry, who made it, when |
| `short_link_clicks` | one row per link per day that had a click: the count |

One migration, named for the moment it is written:
`src/app/phinx/migrations/<YYYYMMDDHHMMSS>_create_short_links_tables.php`.

```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Short links (docs/short-links.md): the links, their daily click counts, and
 * the short_link_status vocabulary (App\Domain\ShortLinks\ShortLinkStatus).
 */
final class CreateShortLinksTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('types')->insert([
            ['types_group' => 'short_link_status', 'types_name' => 'active', 'types_label' => 'Active', 'types_sort' => 10],
            ['types_group' => 'short_link_status', 'types_name' => 'paused', 'types_label' => 'Paused', 'types_sort' => 20],
        ])->saveData();

        $this->execute(<<<'SQL'
            CREATE TABLE `short_links` (
              `short_links_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `short_links_code` VARCHAR(64) NOT NULL,
              `short_links_target` VARCHAR(2048) NOT NULL,
              `short_links_label` VARCHAR(120) NULL,
              `short_links_status_id` INT UNSIGNED NOT NULL,
              `short_links_expires_at` DATETIME NULL,
              `short_links_created_by` INT UNSIGNED NULL,
              `short_links_created_at` DATETIME NOT NULL,
              `short_links_updated_at` DATETIME NOT NULL,
              PRIMARY KEY (`short_links_id`),
              UNIQUE KEY `short_links_code` (`short_links_code`),
              KEY `short_links_status_id` (`short_links_status_id`),
              CONSTRAINT `short_links_status_fk` FOREIGN KEY (`short_links_status_id`) REFERENCES `types` (`types_id`),
              CONSTRAINT `short_links_created_by_fk` FOREIGN KEY (`short_links_created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `short_link_clicks` (
              `short_link_clicks_link_id` INT UNSIGNED NOT NULL,
              `short_link_clicks_day` DATE NOT NULL,
              `short_link_clicks_count` INT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`short_link_clicks_link_id`, `short_link_clicks_day`),
              CONSTRAINT `short_link_clicks_link_fk` FOREIGN KEY (`short_link_clicks_link_id`)
                REFERENCES `short_links` (`short_links_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `short_link_clicks`');
        $this->execute('DROP TABLE IF EXISTS `short_links`');
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'short_link_status'");
    }
}
```

Notes for the agent:

- The creator column is nullable and `ON DELETE SET NULL`: removing a person
  must not remove the links they made.
- `short_links_code` is unique, and the column's case-insensitive collation
  backs up the lowercasing: `Spring-Sale` and `spring-sale` can never both
  exist.
- The list's click totals are aggregates over `short_link_clicks`: compute
  them in a subquery and join that (AGENTS.md §6 rule 3), and write the
  seven-day window as `>= ? AND < ? + INTERVAL 1 DAY` (rule 1).

## API

Every route below sits in `src/config/routes/short-links.php`, the `/api`
ones in an `/api` group behind `AuthMiddleware`, each naming its permission.
Responses use `BaseController`'s envelope: `{success: true, data}` or
`{success: false, error}`.

| Route | Permission | Does |
| --- | --- | --- |
| `GET /api/short-links` | `short-links-view` | every link, newest first |
| `POST /api/short-links` | `short-links-edit` | create; `201` with the link |
| `PUT /api/short-links/{id}` | `short-links-edit` | change `target`, `label`, `expires_at` (never `code`) |
| `PUT /api/short-links/{id}/status` | `short-links-edit` | `{status: "paused"}` or `{status: "active"}` |
| `DELETE /api/short-links/{id}` | `short-links-edit` | delete it and its counts |
| `GET /api/short-links/{id}/clicks?days=30` | `short-links-view` | a count for each of the last `days` days (1 to 365, default 30), oldest first, days with no clicks as 0 |
| `GET /go/{code}` | none (public) | follow the link (see Behaviour) |

A link in a response:

```json
{
  "id": 7,
  "code": "spring-sale",
  "short_url": "https://yoursite.com/go/spring-sale",
  "target": "https://shop.example/collections/spring?utm_source=flyer",
  "label": "Spring flyer",
  "status": "active",
  "expires_at": "2026-12-31T23:59:00-05:00",
  "followable": true,
  "clicks_total": 412,
  "clicks_7_days": 58,
  "created_by": "owner@example.test",
  "created_at": "2026-10-13T09:15:00-05:00"
}
```

Errors: `422` with a message naming the field for an invalid code, target,
label (over 120 characters) or expiry, or a status change the state machine
refuses; `409` when the code is taken; `404` for an unknown `{id}`.

## Interface

One page, **Short links**, at `/short-links`, in the navigation for anyone
with `short-links-view` (`meta: { permission: 'short-links-view', nav: true }`).

- A table of links: the short URL with a **Copy** button, the label, where it
  points (truncated, full on hover), a status badge (Active, Paused or
  Expired), clicks in the last seven days with a small 30-day bar chart drawn
  as inline SVG (no chart library), and the total.
- For `short-links-edit` only: a **New link** form (target, optional code,
  optional label, optional expiry), and on each row **Edit**, **Pause** or
  **Resume**, and **Delete** (with a confirmation naming the code).
- Validation messages appear beside the field they are about, from the
  server's `422`.

The pure parts are utilities with `node:test` tests in
`src/frontend/src/utils/shortLinks.js`: code normalising and checking (the
same rules as the server, for instant feedback), the short URL from the
site's origin and a code, and the bar chart's geometry from a series of
counts.

## Configuration

None. The redirect path is `/go/`, and the day boundary follows the existing
`display_timezone` setting.

## Failure list

1. A target that is not an absolute `http`/`https` URL (`javascript:`,
   `data:`, `//elsewhere`, a bare word) → refused with `422`: the site must
   never become a redirect to arbitrary schemes.
2. A target with a `user:password@` part → refused with `422`.
3. A target longer than 2048 characters, or containing whitespace or control
   characters → refused with `422`.
4. A target on this site's own host under `/go/` → refused with `422`: a link
   to a link is a loop.
5. A code with capitals → stored lowercased; following it in any case works.
6. A code with characters outside `a-z0-9-`, a leading, trailing or doubled
   hyphen, or over 64 characters → refused with `422`.
7. A code already taken → `409`, and nothing is created.
8. A blank code → one is generated from the unambiguous alphabet, unique,
   retried on collision.
9. An attempt to change a link's code → ignored or refused; the code never
   changes.
10. Following an unknown code → `404`.
11. Following a paused link → the same `404`.
12. Following an expired link → the same `404`; moving its expiry later
    revives it.
13. A successful redirect cached by a browser, so a pause or retarget does
    not take effect → prevented with `Cache-Control: no-store` on the `302`.
14. Two clicks at the same moment → both counted.
15. A `HEAD` request or a prefetch (`Sec-Purpose: prefetch`,
    `Purpose: prefetch`) → answered, not counted.
16. A click just after midnight in the display timezone → counted on the
    owner's new day, not UTC's.
17. A `display_timezone` that is not a valid zone → counts fall back to UTC
    days, and nothing fails.
18. A status change the state machine does not allow (pausing a paused
    link) → `422`, nothing changed.
19. A user with `short-links-view` only creates, edits, pauses or deletes →
    `403`; the controls are not shown to them.
20. Signed out, any `/api/short-links` route → `401`; `/go/{code}` still
    works.
21. Deleting a link → its counts go with it, and its code can be used again.
22. Deleting a person who created links → the links stay, with no creator.
23. A clicks request for 0, negative or more than 365 days, or not a
    number → refused with `422`.
24. The click count of a link with no clicks yet → 0, not missing; days
    without clicks in a series → 0.
25. Following a link sends the referring page to the target → prevented with
    `Referrer-Policy: no-referrer`.

## Installation

Follow docs/modules.md ("Installing a module"). Before writing anything, tell
the app's owner what this adds: two permissions and who gets them, two tables,
seven routes (one public), one page. Then, in order:

1. **Doc.** `docs/short-links.md`: the purpose and the failure list above,
   and the line "From the Waffle Iron module short-links 1.0.0."
2. **Tests first**, from `MODULE-TEST.md`. They fail.
3. **Migration**, exactly as above, timestamped now. Add the
   `short_link_status` pair to `tests/Unit/Domain/VocabularyTest.php`.
4. **Domain**, in `src/app/Domain/ShortLinks/`: `ShortCode` (normalise,
   validate, `random()`), `LinkTarget` (validate; given the site's host to
   refuse loops), `ShortLinkStatus` (the enum with `HasTransitions`), and a
   pure rule deciding whether a link is followable from its status, expiry
   and the time, and which day a click belongs to in a timezone.
5. **Permissions**: the two `Permission` cases, `Permissions::GRID`,
   `tests/Support/access-routes.php`, `docs/access-levels.md`.
6. **Model**: `src/app/Models/ShortLinkRepository.php` (create, find by id and
   by code, list with totals, update, change status through the enum, delete,
   record a click, a zero-filled click series) and an entity class.
7. **Controllers**: `ShortLinkController` for the API and
   `ShortLinkRedirectController` for `/go/{code}`, both extending
   `BaseController`. The redirect controller writes its own `302` or `404`
   (not JSON) and never throws for an unknown code.
8. **Wiring**: `src/config/container/short-links.php`,
   `src/config/routes/short-links.php` (the public `/go/{code}` route outside
   any auth group), `tests/Support/Overrides/short-links.php` if anything
   needs a fake, a `short-links` group in `tests/Pest.php`, and its paths in
   `tools/changed-groups.sh`.
9. **Frontend**: `src/frontend/src/utils/shortLinks.js` with its tests, the
   `/short-links` route, and `src/frontend/src/views/ShortLinksView.vue`.
10. **Gate**: `./tools/test.sh --group=short-links`, then
    `./tools/test.sh --coverage` at 100%.
11. **Record**: `modules.json`, the entity in AGENTS.md §4, a row in the
    README's Documentation table, and a line under `[Unreleased]` in
    `CHANGELOG.md`.

## Upgrading

1.0.0 is the first version: nothing to upgrade from.
