# Invitations

How people join an app that is closed to sign-up: an owner or administrator
invites them by email address, and the person sets a password from a
single-use link (RESOLVED_QUESTIONS.md, "How do people join an app?"),
emailed to them from the queue (docs/mail.md).

Built first in KAW Meetup, where it is how organisers join, and brought
back. An invited person holds their level from the start. An open app, where
anyone may register, uses OAuth sign-up instead (not built yet).

## How it works

- **Who may invite whom.** An owner invites administrators and users; an
  administrator invites users. Nobody invites an owner: the first owner comes
  from the CreateInitialOwner migration, and ownership is handed over, not
  invited.
- **The link** is `/invite/<token>`, a random 256-bit token. The database
  keeps only its SHA-256, so a copy of the database opens no invitation. It
  goes to the invited address by email and is never shown in the app: making
  an invitation queues the email, which holds only the invitation's id, and
  the worker issues the link as it sends (docs/mail.md, 4.). **Send again**
  emails a new link, and the earlier one stops working.
- **One use, seven days.** An invitation is `pending`, then `accepted` or
  `revoked` (`App\Domain\Invitations\InvitationStatus`, the `types` group
  `invitation_status`); a pending one past its expiry opens nothing. Inviting
  an address again revokes its pending invitation, so only the newest link
  works.
- **Accepting** makes the account (the invited address, a name, a password of
  at least 12 characters), gives it the invited level, and signs the person
  in, signing out whoever the browser was signed in as.
- An address that already has an account cannot be invited.

## Permissions

| Permission | What it allows | Owner | Administrator | User |
| --- | --- | :---: | :---: | :---: |
| `invitations-manage` | List, make and revoke invitations (administrators: for users only) | ✓ | ✓ | |

Opening and accepting a link needs no account: the token is the permission.

## API

| Route | Permission | Does |
| --- | --- | --- |
| `GET /api/invitations` | `invitations-manage` | every invitation, newest first |
| `POST /api/invitations` | `invitations-manage` | `{email, level}`: the invitation, emailed |
| `POST /api/invitations/{id}/send` | `invitations-manage` | emails a pending invitation again, with a new link |
| `POST /api/invitations/{id}/revoke` | `invitations-manage` | |
| `GET /api/invite/{token}` | none | the address and level a valid link is for |
| `POST /api/invite/{token}/accept` | none | `{name, password}`: makes the account and signs in |

Every link that cannot be used (wrong, malformed, expired, revoked, already
used) answers the same 404, "This invitation link is not valid.", so the
answer says nothing about which.

## Failure list

1. An invitation link works twice.
2. An expired or revoked link works.
3. The token is stored as it is, so a copy of the database opens invitations.
4. An administrator invites an administrator, or anyone invites an owner.
5. An invitation is made for an address that already has an account, or accepting one changes an existing account.
6. Two pending invitations for one address both work.
7. Someone without `invitations-manage` lists, makes or revokes invitations.
8. A link that cannot be used says why (wrong, expired, revoked, used), helping someone guess.
9. A password shorter than 12 characters, or a name that is empty, too long or holds control characters, is accepted.
10. Accepting leaves the person signed out, or still signed in as whoever used the browser before.
11. Two people accept the same link at once and two accounts are made.
12. The link can be read again after it was made.
13. A revoked or accepted invitation is revoked (again), or sent again.
14. The link is kept in the email's job, or anywhere but the email itself (docs/mail.md, 4.).
15. An email that fails to send leaves the invitation with a link nobody was given, or the earlier link stops working.
