# Short links: what working means, and how to prove it

Numbers in brackets are the failure-list items in `MODULE.md` an outcome
covers. Every one of the 25 is covered at least once.

## Desired outcomes

1. Only absolute `http://` and `https://` targets with a host are accepted;
   `javascript:`, `data:`, `//elsewhere.example`, `/relative` and `spring` are
   each refused with `422` naming the target. (1)
2. A target with credentials in it (`https://bank.example@elsewhere.example`)
   is refused with `422`. (2)
3. A target of 2049 characters, or one containing a space, a tab, a newline
   or a control character, is refused with `422`; one of exactly 2048 is
   accepted. (3)
4. A target of `https://<this site's host>/go/anything` is refused with
   `422`; a target on this site outside `/go/` is accepted. (4)
5. Creating `Spring-Sale` stores `spring-sale`, and `/go/spring-sale`,
   `/go/Spring-Sale` and `/go/SPRING-SALE` all redirect. (5)
6. Codes `-sale`, `sale-`, `spring--sale`, `spring_sale`, `spring sale`,
   `café` and a 65-character code are each refused with `422`; `a`, `qr7`
   and a 64-character code are accepted. (6)
7. Creating a second link with a taken code answers `409`, and the number of
   links is unchanged. (7)
8. A link created without a code gets one: six characters, all from
   `abcdefghjkmnpqrstuvwxyz23456789`, different from every existing code;
   with the generator forced to collide once, the second draw is used. (8)
9. Sending `code` in an update leaves the code as it was. (9)
10. `GET /go/unknown` answers `404`, with no `Location` header. (10)
11. A paused link answers the same `404` as an unknown code: same status,
    same body. Resuming it makes it redirect again. (11)
12. A link whose expiry has passed answers the same `404`; setting the expiry
    a day into the future makes it redirect again; a link expiring a minute
    from now still redirects. (12)
13. A redirect is a `302` to the exact target, carrying
    `Cache-Control: no-store` and `Referrer-Policy: no-referrer`. (13, 25)
14. Twenty clicks recorded for the same link on the same day leave a count
    of exactly 20 in one row. (14)
15. `HEAD /go/<code>` and a `GET` with `Sec-Purpose: prefetch` or
    `Purpose: prefetch` are answered like a `GET` but leave the count
    unchanged. (15)
16. With `display_timezone` set to `America/New_York`, a click at 04:30 UTC
    on 14 October is counted on 14 October, and one at 03:30 UTC on 14
    October on 13 October. (16)
17. With `display_timezone` set to `Not/AZone`, clicks are counted on the UTC
    day and the redirect still works. (17)
18. Pausing a paused link, or resuming an active one, answers `422` and
    changes nothing; the domain's transition walk covers every pair of
    statuses, allowed and refused. (18)
19. A user-level session gets `200` from both read routes and `403` from
    create, update, status and delete; the page shows them no New link
    form and no row actions. An administrator gets `2xx` from all of them.
    (19)
20. Signed out, every `/api/short-links` route answers `401`, and `/go/<code>`
    still redirects. (20)
21. Deleting a link removes its click rows, and a new link can then take its
    code. (21)
22. Deleting the person who created a link leaves the link, with
    `created_by` empty. (22)
23. `?days=0`, `?days=-3`, `?days=366` and `?days=many` each answer `422`;
    no `days` gives 30 entries; `?days=7` gives 7, oldest first. (23)
24. A link never followed lists `clicks_total: 0` and `clicks_7_days: 0`,
    and its 30-day series is thirty zeros; a series with clicks on two days
    has zeros everywhere else. (24)

## How to test

Write these before the code they cover, in this order (docs/adding-a-feature.md),
and cite the outcome numbers in each test's name or a comment.

**Domain units**, `tests/Unit/Domain/ShortLinks/`:

- `ShortCodeTest`: normalising and every accepted and refused code (5, 6);
  `random()` uses only the unambiguous alphabet and has length six (8).
- `LinkTargetTest`: every accepted and refused target, including the
  2048/2049 edge and the loop rule with a given site host (1, 2, 3, 4).
- `ShortLinkStatusTest`: the exhaustive every-pair transition walk, copied
  from `HasTransitionsTest` (18).
- The followable rule: active and future expiry, active and no expiry,
  paused, expiry passed, expiry exactly now (11, 12). The click-day rule:
  the two New York instants and the invalid zone (16, 17).

**Repository**, `tests/Unit/Models/ShortLinkRepositoryTest.php`, against the
real migrated schema inside the rolled-back transaction: create and find by
code in any case (5); a unique-code violation is reported, not thrown raw
(7); twenty `recordClick` calls make one row of 20 (14); the list's totals
and seven-day counts, including zero for a link never followed (24); the
zero-filled series (23, 24); delete cascades to clicks and frees the code
(21); deleting the creator leaves the link (22). Add `createShortLink()` to
`tests/Support/Factory.php` for the other tests to use.

**Feature tests through the router**:

- `tests/Feature/ShortLinksTest.php`: each API route's success shape and
  every `422`, `409` and `404` above (1-9, 18, 21, 23, 24); the role
  matrix (19) beyond what `AccessGridTest` already proves from
  `access-routes.php`; signed-out `401`s (20).
- `tests/Feature/ShortLinkRedirectTest.php`: the `302` and its headers (13,
  25), every `404` being identical (10, 11, 12), case-insensitive codes (5),
  `HEAD` and prefetch not counted (15), signed-out redirects working (20),
  and the day a click lands on with a fixed clock and each timezone (16,
  17). Use `ClockPort`'s fake to fix the time.

**End to end**, `tests/Feature/E2E/ShortLinksJourneyTest.php`, in both the
`short-links` and `e2e` groups. As an administrator: create `spring-sale`
pointing at a target; follow it three times signed out; pause it; follow it
once (404); resume it; retarget it; follow it twice; read its clicks. The
artifact records, by role: the code, each response's status and `Location`,
the paused response's body, the final total (5) and today's count (5). Two
runs on unchanged code produce the same digest.

**Frontend**, `src/frontend/test/shortLinks.test.js`: the code rules match
the server's on the same examples (5, 6); the short URL is built from an
origin and a code without doubled slashes; the bar chart's bars scale to the
largest count, draw zero-count days as empty bars, and cope with a series of
all zeros (24).

**Manually, once**, in a browser: create a link, scan the short URL as a QR
code with a phone or open it in a private window, and see the count go up;
pause it and see the phone get the 404; as a user-level account, see the
list without any controls.

## Definition of done

- `./tools/test.sh --group=short-links` is green.
- `./tools/test.sh --coverage` is green at 100%, the whole suite included.
- The E2E artifact exists under `coverage/e2e/` and its digest is stable
  across two runs.
- The module's tests were not weakened to pass: every outcome above is
  asserted as written.
