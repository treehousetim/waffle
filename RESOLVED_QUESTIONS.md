# Resolved questions

Questions moved out of `OPEN-QUESTIONS.md` once answered, kept for the reasoning.
Each starts with where it was settled. Newest moves at the top.

---

## Where do secrets live?

**Resolved by the template:** Doppler, and only Doppler — local and production —
with three configs (`dev`, `prd`, `prod-deploy`) that inherit nothing from each
other. There is no `.env` file anywhere. Tests read the committed
`.env.testing`, which holds no secrets. AGENTS.md §8.

## How is the app deployed?

**Resolved by the template:** GitHub Actions on `release: published`, gated by a
`v*` tag policy on the `prod` environment, builds the frontend and `vendor/` on
the runner and rsyncs one tested tree to the server, where `deploy/deploy.sh`
brings it into service. Nothing is cloned on the server. AGENTS.md §9.
