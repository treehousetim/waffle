# Password reset

How someone who has forgotten their password sets a new one: an emailed,
single-use link (RESOLVED_QUESTIONS.md, "How do people join an app?"). Built
2026-11-07, on the mail slice (docs/mail.md).

## How it works

- **Asking.** The sign-in page's "Forgot your password?" asks for an address.
  The answer is always the same, "If that address has an account, we have
  emailed it a link.", whether or not it has one (1.). For an enabled,
  verified account, a request is recorded (`password_resets`) and its email
  queued in the same transaction; the job holds only the request's id.
- **The email.** The worker issues the link as it sends: `/reset/<token>`, a
  random 256-bit token, of which the database keeps only the SHA-256 (3.). The
  link is kept only once the email has gone (9.).
- **One use, one hour, the newest only.** A request is good for an hour from
  when it was made. Asking again closes the earlier ones, and setting a
  password closes every open one for the account (2., 7.).
- **At most three an hour** per account: a fourth request in the hour sends
  nothing, with the same answer (4.).
- **Setting the password** takes one of at least 12 characters (6.), under
  the request's row lock, so two uses of one link at once take turns and the
  second finds it used (10.). The account is then emailed a notice that its
  password was changed (8.), and the person signs in with the new one.

## API

| Route | Permission | Does |
| --- | --- | --- |
| `POST /api/auth/password/forgot` | none | `{email}`: always the same answer |
| `GET /api/password-reset/{token}` | none | the address a valid link is for |
| `POST /api/password-reset/{token}` | none | `{password}`: sets it |

Every link that cannot be used answers the same 404, "This link is not valid.
Ask for a new one.", so the answer says nothing about which.

## Failure list

Each has a test: `tests/Unit/Libraries/Auth/PasswordResetsTest.php`,
`tests/Feature/PasswordResetTest.php` and
`tests/Schema/PasswordResetTransactionTest.php`.

1. The answer to a request says whether the address has an account.
2. A link works twice, after its hour, or after a newer one was asked for.
3. The link is stored as it is: in the email's job, or in the database.
4. Somebody floods an account with reset emails.
5. An account that is turned off, or was never verified, is sent a link.
6. A password shorter than 12 characters is accepted.
7. Setting a password leaves another open link for the account working.
8. Nobody is told that the password was changed.
9. An email that fails to send leaves a link nobody was given as the working one.
10. Two uses of one link at once both set a password.
