# Resolved questions

Questions moved out of `OPEN-QUESTIONS.md` once answered, kept for the reasoning.
Each starts with where it was settled. Newest moves at the top.

---

## Where do secrets live?

**Resolved by the template:** Doppler, and only Doppler — local and production —
with two configs, named for who reads them, that inherit nothing from each
other: `prod-serve` (the server) and `prod-deploy` (GitHub Actions). Until
2026-10-12 there were three (`dev`, `prd`, `prod-deploy`); `dev` held nothing
the compose defaults did not, so it went, along with `doppler.yaml`, and `prd`
was renamed. A `dev` comes back when a feature first needs a local key. There is no `.env` file anywhere. Tests read the committed
`.env.testing`, which holds no secrets. AGENTS.md §8.

## How is the app deployed?

**Resolved by the template:** GitHub Actions on `release: published`, gated by a
`v*` tag policy on the `prod` environment, builds the frontend and `vendor/` on
the runner and rsyncs one tested tree to the server, where `deploy/deploy.sh`
brings it into service. Nothing is cloned on the server. AGENTS.md §9.
