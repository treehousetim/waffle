# Accounts and keys

Every outside account the app needs, what to create in each, and where each
key goes. Keys go into **Doppler**, through its website, and nowhere else:
never into a file, a chat, a ticket or a command line. The server reads them
from Doppler on its own, so after the server is set up you never type a key
on it.

| Account | Needed | What it holds or does | Key you create | Where the key goes |
| --- | --- | --- | --- | --- |
| [GitHub](https://github.com) | yes | The code, the tests on every release, the release files | A read-only token for the server | Doppler `prod`: `GITHUB_RELEASES_TOKEN` |
| | with a site | The site's rebuild when published content changes | A token that may notify the site's repository | Doppler `prod`: `SITE_DISPATCH_TOKEN` |
| [Doppler](https://www.doppler.com) | yes | Every production secret | A service token for the server | Typed once on the server (docs/fresh-install.md) |
| The app itself | yes | The first owner, the admin who signs in first | An email address and a password, one pair for `dev` and a different one for `prod` | Doppler `dev` and `prod`: `FIRST_USERNAME`, `FIRST_USER_PASSWORD` |
| [DigitalOcean](https://www.digitalocean.com) | yes | The server, and the domain's DNS | Your SSH key | Your own machine; its public half on the server |
| A domain registrar | yes | Your domain | none | Point its nameservers at DigitalOcean |
| [Resend](https://resend.com) | when mail is built | Sending the app's email | An API key | Doppler `prod`: `RESEND_API_KEY` |
| [healthchecks.io](https://healthchecks.io) | optional | Noticing when the app stops answering | A check's ping URL | Doppler `prod`: `HEARTBEAT_URL` |
| [Slack](https://slack.com) | optional | Hearing about a failed or refused deploy | An incoming-webhook URL | Doppler `prod`: `DEPLOY_ALERT_WEBHOOK` |

## GitHub

The repository lives here, GitHub Actions builds every release, and the server
downloads the built release from it.

**The server's token, `GITHUB_RELEASES_TOKEN`.** The server uses it to see the
latest release and download its files. It can read the repository, code
included, and change nothing; the server uses it for the release files only.

1. GitHub → your picture → **Settings** → **Developer settings** →
   **Personal access tokens** → **Fine-grained tokens** → **Generate new token**.
2. Name: `waffle server`. Expiration: one year (put a reminder in your
   calendar a week before).
3. Repository access: **Only select repositories** → this project's repository.
4. Permissions → Repository permissions → **Contents: Read-only**. Leave every
   other permission at "No access" (GitHub adds Metadata: Read-only itself).
5. **Generate token**, copy it, and paste it into Doppler (below) as
   `GITHUB_RELEASES_TOKEN`. GitHub shows it only once; if it is lost, make a
   new one.

When it expires, deploys stop and the server alerts that GitHub is refusing
it. Make a new one and replace the value in Doppler; nothing else changes.

**The rebuild token, `SITE_DISPATCH_TOKEN`**, only with a Waffle Site beside
the app. When a record the site publishes changes, the app tells the site's
repository so it rebuilds the pages that changed (docs/content.md). Make it
the same way, with these differences:

- Name: `waffle change notices`.
- Repository access: **Only select repositories** → the site's repository.
- Permissions → **Contents: Read and write** (GitHub's rule for sending a
  repository a notice).
- Paste it into Doppler `prod` as `SITE_DISPATCH_TOKEN`.

**Protect `main`** (Settings → Branches): pull requests required.

**Protect the release tags** (Settings → Rules → Rulesets → New tag ruleset):
target every tag (`~ALL`), restrict creations, updates and deletions, and add
yourself to the bypass list. Only you can then make, move or delete a tag, and
a release's tag is what deploys.

## Doppler

The only place production secrets are kept.

1. Create the project `waffle`, with the configs `dev` (your machine)
   and `prod` (the server).
2. Put every key in this page's table into `prod`, through the website:
   `prod` → **Add Secret** → name and value → **Save**.
3. docs/fresh-install.md, steps 5 and 6, adds the ones nobody issues you
   (generated passwords) and the first owner.
4. **The admin, in both.** `FIRST_USERNAME` (an email address) and
   `FIRST_USER_PASSWORD`, a different pair in `dev` and in `prod`, so the
   production password is never one you type locally. `prod`'s is read once,
   by the first deploy; `dev`'s by the local seeder (README.md, "First run").
   Neither is in any file.
5. **The server's token:** `prod` → **Access** → **Generate** a service
   token, read-only. This is the one value typed on the server, once
   (docs/fresh-install.md); copy it straight there.

## DigitalOcean

The server (a droplet) and the domain's DNS.

1. **Your SSH key.** On your own machine:
   `ssh-keygen -t ed25519 -f ~/.ssh/waffle`, then add the public half
   (`~/.ssh/waffle.pub`) under Settings → Security → SSH keys. It is how you
   log in; nothing else uses it.
2. **The droplet:** Debian 13, 2 GB, with only that SSH key ticked
   (docs/fresh-install.md, step 7).
3. **DNS:** Networking → Domains → add your domain, and an A record for each
   hostname the server serves (`waffle.example.com`, and a site's beside it)
   pointing at the droplet. No AAAA record unless the droplet has IPv6 turned
   on.

## The domain registrar

Wherever the domain was bought: set its nameservers to `ns1.digitalocean.com`,
`ns2.digitalocean.com` and `ns3.digitalocean.com`.

## Resend

Sends the app's email: invitations and password resets, once mail is built.

1. Add your domain (Domains → Add Domain). Resend shows the
   records to create; add each one in DigitalOcean's DNS exactly as shown, and
   wait for Resend to mark the domain verified.
2. Add a DMARC record in DigitalOcean's DNS too: a TXT record named `_dmarc`
   with the value `v=DMARC1; p=none;`.
3. API Keys → **Create API Key**: name `waffle production`, permission
   **Sending access**, that domain. Copy it into Doppler `prod`
   as `RESEND_API_KEY`.

## Optional

- **healthchecks.io:** create a check with a five-minute period; copy its ping
  URL into `HEARTBEAT_URL`. It alerts you when the app's health check stops
  arriving.
- **Slack:** create an incoming webhook for the channel you want; copy its URL
  into `DEPLOY_ALERT_WEBHOOK`. The server posts there when a release fails, is
  refused, or GitHub stops answering.
