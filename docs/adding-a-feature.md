# Adding a feature

The order is the method: **failures, then tests, then code.** A feature lands
as a *slice* — its own files in the places below — so it touches no shared
file and its tests can be run on their own.

The `settings` slice ([settings.md](settings.md)) is a finished example of
every step. Copy its shape.

## 1. Write the doc and the failure list

Create `docs/<slice>.md`. Before any code, it holds:

- **What it is for**, in two or three sentences, and what it is the system of
  record for.
- **The failure list**: every way it can go wrong, numbered, one line each,
  with what should happen instead. Be exhaustive where the feature meets
  something outside this process — a third-party API, a webhook, a file, a
  clock, a second request racing the first, a person with the wrong level.

```markdown
## Failure list

1. The provider times out → the job is retried once, then marked failed with the reason.
2. The webhook signature does not match → 401, nothing recorded but the rejection.
3. The same delivery arrives twice → 200, applied once.
4. A user without `widgets-edit` posts → 403.
5. Two saves of one widget race → the second sees the first's version and is refused with 409.
```

Tests and code comments cite these numbers (`// 3.`), so the reason for a line
of code is one lookup away. The list is never finished: a bug found later is a
new number and a new test first.

Add anything the brief is silent on to `OPEN-QUESTIONS.md` rather than
guessing.

## 2. Write the tests

In this order, each failing before its code exists:

1. **Domain unit tests** — `tests/Unit/Domain/<Slice>/`. Every value object's
   refusals, every state machine's edges (allowed and forbidden: copy the
   exhaustive walk in `tests/Unit/Domain/Status/HasTransitionsTest.php`).
2. **Repository tests** — `tests/Unit/Models/<Thing>RepositoryTest.php`,
   against the real migrated schema inside the rolled-back transaction.
3. **Feature tests** — `tests/Feature/<Slice>Test.php`, through the router:
   status codes, JSON shape, error messages, and every number on the failure
   list that is reachable from HTTP. Add each new route to
   `tests/Support/access-routes.php` with its permission; `AccessGridTest`
   then checks 401 and 403 for every level by itself.
4. **An E2E test** for anything with more than one step —
   `tests/Feature/E2E/<Slice>Test.php` — that walks the whole feature and ends
   with an `E2EArtifact` (facts named by role, never by id; checks; digest).
5. **Frontend**: pure logic goes in `src/frontend/src/utils/` with a
   `test/<name>.test.js`.

If the feature talks to an outside service, the port's fake comes before its
tests: `tests/Support/Fakes/Fake<Port>.php`, throwing on any call unless given
canned answers, recording the calls it receives.

## 3. Write the code

| What | Where |
| --- | --- |
| Pure logic, value objects, state machines (enum + `HasTransitions`) | `src/app/Domain/<Slice>/` |
| A port for each outside system | `src/app/Domain/Ports/<Thing>Port.php` |
| The port's real adapter | `src/app/Libraries/<Slice>/` (or `Libraries/Clients/` if it only reaches the network — excluded from coverage, so keep it thin) |
| Tables, lookup rows, reference data | a new migration in `src/app/phinx/migrations/` |
| Repositories and entities | `src/app/Models/` |
| HTTP handlers | `src/app/Controllers/<Slice>Controller.php`, extending `BaseController` |
| Pages | `src/frontend/src/views/`, with `meta.permission` on the route |

New permissions: see [access-levels.md](access-levels.md#adding-a-permission).
New statuses or types: an enum case **and** a migration inserting its `types`
row, plus a line in `tests/Unit/Domain/VocabularyTest.php`.

## 4. Wire the slice

Four files, none of them shared:

```
src/config/container/<slice>.php     # PHP-DI definitions: every class, explicitly
src/config/routes/<slice>.php        # its /api group with AuthMiddleware, each route with $can(...)
tests/Support/Overrides/<slice>.php  # each of its ports bound to its throwing fake
```

…and two one-line edits:

- `tests/Pest.php` — `uses()->group('<slice>')->in(...)` naming its test
  directory and files. `ArchGroupsTest` fails until the container slice has a
  group of the same name and every test file is in a group.
- `tools/changed-groups.sh` — map `src/app/Domain/<Slice>/*` and its tests to
  the group, so `./tools/test.sh --changed` finds them.

## 5. Gate, document, release

```bash
./tools/test.sh --group=<slice>     # while iterating
./tools/test.sh --coverage          # before committing: 100%, everything
```

Then: the entity in AGENTS.md §4, the doc linked from `docs/README.md`, a line
under `[Unreleased]` in `CHANGELOG.md`, and any new config key in
`docker-compose.yml`'s `php` environment, `src/config/container.php`,
`.env.testing` (a dummy) and Doppler.
