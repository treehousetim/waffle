# Starting a project from Waffle

```bash
composer create-project treehousetim/waffle acme
```

Composer copies Waffle into `acme/`, installs its dependencies, and then runs
`Installer\CreateProject::postCreate` (`tools/installer/CreateProject.php`),
which hands over to the guided setup, `tools/setup.sh`. Someone who downloaded
or cloned the repository runs that script by hand; it is the one flow either
way. It checks the machine, asks three questions, renames the project with
`tools/rename-project.sh`, refreshes the lock files, and offers the first
commit. Each step is skipped if it is already done, so it is safe to run again.

| Question | Suggested | Becomes |
| --- | --- | --- |
| Slug | the directory name, lowercased, other characters as `_` | database names (`acme`, `acme_test`), the Doppler project, `/opt/acme` on the server, the composer package `acme/acme` |
| Display name | the slug with words capitalised | page titles, the hello message, the `app_name` setting |
| Production hostname | `<slug>.example.com` | the site address Caddy gets a certificate for, the workflow's environment URL |

Run without a terminal (`--no-interaction`, CI), the installer asks nothing and
renames nothing. The script takes every answer as a flag instead:

```bash
./tools/setup.sh --slug acme --name "Acme Ops" --host ops.acme.com --yes
./tools/setup.sh --help
```

`rename-project.sh` can still be run on its own, once:

```bash
./tools/rename-project.sh acme "Acme Ops" ops.acme.com
```

## Platforms

macOS (its stock bash 3.2 and BSD tools), Linux, and Windows inside WSL2. Both
scripts use only POSIX tools that behave the same on BSD and GNU userlands.
Native Windows shells (Git Bash, MSYS2, Cygwin) are refused with a pointer to
WSL2: the stack needs a real Linux or macOS userland, and a half-working
rename is worse than none.

`composer.lock` is refreshed (its hash covers the project name) with
`composer` if it is installed, otherwise a throwaway `composer:2` container;
neither starts the project's stack. With neither, the step is skipped with
the command to run later. `package-lock.json` needs no refresh: the rename
rewrites the name in it, and npm only ever runs through `tools/npm.sh`
(docs/supply-chain.md).

## What the script replaces

The three names Waffle ships under, everywhere outside `vendor/`,
`node_modules/`, `.git/`, `docker/data/` and `coverage/`:

- `waffle.example.com` → the hostname (first, so the order cannot matter)
- `Waffle` → the display name
- `waffle` → the slug

Then three things that describe Waffle itself rather than a placeholder:

- `composer.json`'s `name` becomes `<slug>/<slug>` and its `description` the
  display name.
- README's backronym line becomes a line saying the project is built on Waffle,
  linking `https://github.com/treehousetim/waffle`.
- The project starts its own release stream (docs/releasing.md): `VERSION`
  becomes `0`, never released, and `CHANGELOG.md` starts fresh, its first line
  recording the framework version it was started from.

Any line containing `treehousetim/waffle` is left alone, so that link, and
anything else pointing at the framework, survives. This document, both scripts,
the installer, `LICENSE`, `docs/modules.md`, everything in `modules/`,
`ModulesTest`'s fixture module (`tests/Support/Fixtures/modules/`), and the
module command with its tests (`tools/module.sh`, `tools/module/`,
`docs/module-command.md`, `tests/Unit/ModuleTool/`,
`tests/Unit/ModuleCommandTest.php`, `tests/Support/ModuleFixtures.php`) are
never touched: they name the framework, Waffle Iron and `.waffle/` on
purpose.

## Failure list

Each has a test in `tests/Unit/CreateProjectTest.php`, which runs the scripts
against a scratch copy of the files they edit.

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
   the Caddy site address, where a space or semicolon is a syntax error at deploy.
7. The script runs over an existing `docker/data/`, whose databases were
   created under the old names and would no longer be found.
8. A refused run edits some files before refusing.
9. The script renames the installer's own namespace or autoload entry, so the
   next `composer install` cannot find the class it names.
10. A second run renames the framework link it wrote in the first.
11. Run without a terminal, the installer picks names on the user's behalf
    instead of leaving the placeholders for them to choose.
12. The suggested slug for an awkward directory name (`My App`, `2024-site`,
    `ACME`) is itself invalid.
13. The setup script needs a terminal even when every answer is given as a
    flag, so it cannot run in CI.
14. Without a terminal and without answers, the setup script guesses, or waits
    forever for input that will never come.
15. The setup script swallows the rename script's refusal and reports success,
    or edits files before it refuses.
16. Run a second time, the setup script renames an already-named project
    again.
17. On a native Windows shell the setup script half-works instead of stopping
    with a pointer to WSL2.
18. The new project inherits the framework's `VERSION` and changelog, so its
    first release is numbered after the framework's and its changelog tells the
    framework's history instead of its own.

19. A project made from Waffle fails its own tests, because tests that only make
    sense in the framework came with it. → The rename removes them
    (`tests/Unit/CreateProjectTest.php`), and no test writes a placeholder in a
    form the rename cannot reach.
20. The framework's companions are renamed with the project, so its documents
    send people to a "<Name> Site" or "<Name> Iron" that does not exist. → The
    rename keeps `Waffle Site`, `Waffle Iron`, `waffle-site`, `waffle-iron`
    and `waffle-api.md` as they are.