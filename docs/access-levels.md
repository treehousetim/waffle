# Access levels

Three levels, one list of permissions, one table saying which level holds
which. Everything that decides access asks that table.

| Level | Identifier | Delight\Auth role | Bitmask |
| --- | --- | --- | --- |
| Owner | `owner` | `ADMIN` | 1 |
| Administrator | `administrator` | `MANAGER` | 8192 |
| User | `user` | `EMPLOYEE` | 2048 |

An account with no level can sign in and is refused everything else. An
account carrying two bits is its higher level (`Role::fromMask`).

## The grid

`App\Domain\Auth\Permissions::GRID`. The owner holds every permission, so only
the two lower levels are listed there.

| Permission | What it allows | Owner | Administrator | User |
| --- | --- | :---: | :---: | :---: |
| `home` | The home page (`GET /api/hello`) | ✓ | ✓ | ✓ |
| `settings-view` | Read the Settings page (`GET /api/settings`) | ✓ | ✓ | |
| `settings-edit` | Change settings (`PUT /api/settings`) | ✓ | | |
| `queue-manage` | See the queue and its failed jobs, and retry or discard them (`/api/queue`, docs/queue.md) | ✓ | ✓ | |
| `content-edit` | Write and edit records the site publishes (docs/content.md) | ✓ | ✓ | |
| `content-publish` | Publish, retire and restore records | ✓ | ✓ | |
| `invitations-manage` | Invite people, and revoke invitations (administrators: users only; docs/invitations.md) | ✓ | ✓ | |
| `forms-manage` | Read what the site's forms sent, and mark it handled or spam (docs/forms.md) | ✓ | ✓ | |
| `sites-manage` | Add the sites that send forms, issue their keys, disable them | ✓ | | |

Keep this table and `tests/Unit/Domain/Auth/PermissionsTest.php` saying the
same thing; change both together.

## How it is enforced

- **A1. Every `/api` route names its permission** with `->add($can(Permission::X))`
  inside a group that carries `AuthMiddleware`, so a stranger gets 401 before a
  level is asked about. `PermissionMiddleware` answers 403 with
  "Your access level does not include this."
- **A2. No route can be added unguarded.** `tests/Support/access-routes.php`
  lists every `/api` route with its permission (or as deliberately
  unguarded), written apart from the route files. `AccessGridTest` fails when
  the registered routes and that list differ, and walks every guarded route as
  every level, plus no level, checking that exactly the levels lacking the
  permission get 403.
- **A3. A level that may not do something does not see it.** `/api/auth/me`
  and the login response carry `permissions`; the frontend's router guard and
  navigation both use `canSee()` (`src/frontend/src/utils/permissions.js`) over
  each route's `meta.permission`, and buttons check the list directly. Hiding
  is presentation; the server's refusal is the control.
- **A4. Revocation is immediate.** Delight resynchronises the session against
  the database on every request (the container passes `0` as the resync
  interval), and `AuthMiddleware` also checks the account's `status` itself:
  an account turned off is signed out with 403 on its next request, including
  in a tab already open.

## Adding a permission

1. A case in `App\Domain\Auth\Permission` (kebab-case value).
2. The levels that hold it in `Permissions::GRID`, and in the table above.
3. The route: `->add($can(Permission::YourCase))`.
4. A line in `tests/Support/access-routes.php`.
5. `meta.permission` on the frontend route, if it is a page.

`AccessGridTest` and `PermissionsTest` fail until 2–4 agree.
