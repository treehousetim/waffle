# Starting a project from Waffle

```bash
composer create-project treehousetim/waffle acme
```

Composer copies Waffle into `acme/`, installs its dependencies, and then runs
`Installer\CreateProject::postCreate` (`tools/installer/CreateProject.php`),
which asks three questions and hands the answers to `tools/rename-project.sh`:

| Question | Default | Becomes |
| --- | --- | --- |
| Slug | the directory name, lowercased, other characters as `_` | database names (`acme`, `acme_test`), the Doppler project, `/opt/acme` on the server, the composer package `acme/acme` |
| Display name | the slug with words capitalised | page titles, the hello message, the `app_name` setting |
| Production hostname | `<slug>.example.com` | the certificate path in `docker/nginx/production.conf`, the workflow's environment URL |

Run without a terminal (`--no-interaction`, CI), it asks nothing and renames
nothing; run the script yourself afterwards. `--no-scripts` skips it
altogether. The script can also be run by hand, once, on a clone:

```bash
./tools/rename-project.sh acme "Acme Ops" ops.acme.com
```

## What the script replaces

The three names Waffle ships under, everywhere outside `vendor/`,
`node_modules/`, `.git/`, `docker/data/` and `coverage/`:

- `waffle.example.com` → the hostname (first, so the order cannot matter)
- `Waffle` → the display name
- `waffle` → the slug

Then two things that describe Waffle itself rather than a placeholder:

- `composer.json`'s `name` becomes `<slug>/<slug>` and its `description` the
  display name.
- README's backronym line becomes a line saying the project is built on Waffle,
  linking `https://github.com/treehousetim/waffle`.

Any line containing `treehousetim/waffle` is left alone, so that link, and
anything else pointing at the framework, survives. This document, the script,
the installer and `LICENSE` are never touched.

## Failure list

Each has a test in `tests/Unit/CreateProjectTest.php`, which runs the script
against a scratch copy of the files it edits.

1. A placeholder survives somewhere outside the protected lines.
2. `composer.json` keeps Waffle's package name or description, so the new
   project would claim to be `treehousetim/waffle` on Packagist.
3. The link back to Waffle is renamed along with everything else and ends up
   pointing at a repository that does not exist.
4. A slug that is not a valid MariaDB database name is accepted (`Acme`,
   `acme-ops`, `1acme`), and the stack fails later at `docker compose up`.
5. A display name carrying a quote, backslash, `$`, `&`, `|` or angle bracket
   is accepted, and breaks a single-quoted PHP string, a JSON file, an HTML
   title or the `sed` expression that writes it.
6. A hostname that is not a plain lowercase DNS name is accepted, and lands in
   the nginx config, where a space or semicolon is a syntax error at deploy.
7. The script runs over an existing `docker/data/`, whose databases were
   created under the old names and would no longer be found.
8. A refused run edits some files before refusing.
9. The script renames the installer's own namespace or autoload entry, so the
   next `composer install` cannot find the class it names.
10. A second run renames the framework link it wrote in the first.
11. Run without a terminal, the installer picks names on the user's behalf
    instead of leaving the placeholders for them to choose.
12. The installer's suggested slug for an awkward directory name (`My App`,
    `2024-site`, `ACME`) is itself invalid.
