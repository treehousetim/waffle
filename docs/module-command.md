# The module command

`./tools/module.sh` finds a module in [Waffle Iron](https://github.com/treehousetim/waffle-iron),
fetches exactly the files that were reviewed, refuses anything that does not
check out, and hands the build to an agent. It ships in Waffle, so every app
started from [treehousetim/waffle](https://github.com/treehousetim/waffle) has
it. It is how [docs/modules.md](modules.md)'s "Installing a module" is done in
practice; the steps there remain the definition of an install.

```bash
iron use short-links                           # from anywhere inside a Waffle app
```

```bash
./tools/module.sh search [words]               # modules in the catalogue
./tools/module.sh info <name>                  # versions, Waffle constraint, proven-on, source
./tools/module.sh use <name>[@<version>]       # check, show, stage, hand to the agent; `install` is an alias
./tools/module.sh record <name>                # after the build: run the suite, then write modules.json
./tools/module.sh outdated                     # installed modules with a newer listed version
./tools/module.sh upgrade <name>               # the newest version, handed to the agent with its Upgrading entries
./tools/module.sh verify                       # installed modules against the catalogue as it is now
./tools/module.sh doctor                       # is this a Waffle app, is Docker up
```

It is the part of an install that must be the same every time. The part that
cannot be (building the feature into this app) is the agent's, and the
command never takes the agent's word as proof: it runs the suite itself.

## What it is for

The **system of record** for nothing new. It reads the catalogue, writes the
verified module into `.waffle/modules/<name>@<version>/`, and writes
`modules.json` (docs/modules.md, step 4). The catalogue's clone lives in
`.waffle/iron/` and refused or finished downloads in `.waffle/scratch/`.
`.waffle/` ignores itself (it holds a `.gitignore` of `*`), so nothing in it
is committed and the project rename has no line to rewrite.

## The `iron` launcher

`iron use short-links`, from anywhere inside an app, is this command. `iron`
is a small launcher installed once per machine, like `brew`. It lives in
[Waffle Iron](https://github.com/treehousetim/waffle-iron) (`install.sh`),
not here, and holds no logic: it walks up to the first directory holding
`VERSION`, `AGENTS.md`, `composer.json` and an executable `tools/module.sh`,
prints that root, and runs `exec "<root>/tools/module.sh" "$@"`. Everything
that judges a module is therefore here, versioned with the app and tested
with it.

So this command keeps that contract: the entry point is `tools/module.sh`,
its first argument is the subcommand, it works whatever directory it is
started from, and an app is recognised by those four files (`doctor` checks
the same four).

`tools/setup.sh` ends by printing Waffle Iron's install line if `iron` is not
on the PATH. It never installs it.

## Which Waffle version an app is on

 A module's `waffle` constraint is about
the framework, and an app's `VERSION` is the app's own release number (1, 2,
3), so the command reads the version the app was started from: the line
`Started from [treehousetim/waffle vX.Y.Z]` that the rename writes at the top
of `CHANGELOG.md`. In Waffle's own repository, `VERSION` is that version.

## How it is built

- **`tools/module.sh`** is bash 3.2 for macOS, Linux and WSL2, like
  `tools/setup.sh`. It only dispatches, asks, starts the agent and runs
  `./tools/test.sh`. No `eval`; nothing from a module or the catalogue is
  ever executed or interpolated into a command, and a module's name and
  version are checked against `^[a-z][a-z0-9]*(-[a-z0-9]+)*$` and
  `^\d+\.\d+\.\d+$` before they are passed anywhere.
- **Everything that judges a file is PHP**, under `tools/module/` (namespace
  `ModuleTool\`), run in the php service's container the way
  `tools/composer.sh` runs Composer: `docker compose run --rm --no-deps -T`,
  as the invoking user (so what it writes is theirs), with `HOME=/tmp` and
  nothing from the home directory. The image has git. It is held to 100%
  coverage like `src/app`: it is the security boundary, so every refusal
  below is a test. Only `tools/module/run.php` (three lines that build the
  command) and `tools/module/Clients/` (the one class that opens an HTTPS
  connection) are outside coverage, for the reason AGENTS.md accepts.
- **The format rules live in `ModuleTool\Format`**, and
  `tests/Unit/ModulesTest.php` calls it, so the format check and the command
  enforce the same rules.
- **No new library.** The `waffle` constraint is restricted to
  space-separated comparisons (`>=0.1 <1.0`; operators `>=`, `>`, `<=`, `<`,
  `=`; versions `X`, `X.Y` or `X.Y.Z`, missing parts being 0), with `||`
  between alternatives. `ModuleTool\Constraint` is a few tested lines, and
  the format check refuses anything else, so a module cannot carry a
  constraint the command cannot read. Adopting `composer/semver` later is an
  AGENTS.md §2 approval.

## `use`, in order

Each step stops the install with a reason when it fails; nothing is written
outside `.waffle/` before the agent starts, and nothing outside
`.waffle/scratch/` before step 9.

1. **Catalogue.** Clone `https://github.com/treehousetim/waffle-iron` into
   `.waffle/iron/`, or fetch and fast-forward it. The URL is fixed in the
   code. A clone whose `origin` is anything else is refused. A catalogue
   whose history was rewritten (the fetched `main` is not a fast forward) is
   refused and the old clone kept: Waffle Iron's `main` forbids force-pushes,
   so a rewrite means something is wrong.
2. **Schema.** `index.json` must parse and carry a `schema` this command
   knows (1). A newer one is refused with "update tools/module.sh from
   Waffle": that is how the catalogue retires a command whose checks are out
   of date.
3. **Version.** The newest version (or the one named) that is reviewed
   (`reviewed-by` not null), not `yanked`, and whose `waffle` constraint
   matches this app's Waffle version. A named version that fails any of
   those is refused, saying which; with none named and none eligible, every
   version is listed with its reason. The chosen entry's fields are checked
   before use: `form` one of `directory`, `zip`, `file`; a repository URL of
   the form `https://host/owner/repo`; a 40-character commit; a path that is
   `.` or relative with no `..`; a 64-character `sha256`.
4. **Fetch.** Into `.waffle/scratch/<run>/`. A repository is cloned, and the
   pinned commit must be in it and on one of its own branches or tags:
   GitHub serves a fork's commits under the parent's URL, and a clone
   carries only the repository's own, so a commit reachable only by its hash
   is refused. The module is exported with `git archive <commit> <path>`,
   never taken from the working copy, so `.git/` is never part of what is
   checked. A `zip` or `file` source is fetched over HTTPS only, follows at
   most five redirects, each of them HTTPS, and stops at 10 MB.
5. **Unpack** (`zip`): refused if any entry has an absolute path, a
   backslash, a `..` segment, lies outside `<name>/`, is a symlink, or the
   total unpacked is over 10 MB (counted while writing, not taken from the
   zip's own claims).
6. **Contents.** Refused if there is a symlink or any other non-regular file,
   a file name with anything but printable ASCII, or, in a text file, a
   control character other than tab, newline and carriage return, a Unicode
   format character (category Cf: zero-width characters, bidi controls,
   U+FEFF, tag characters U+E0000–U+E007F), a line or paragraph separator, or
   a variation selector (U+FE00–U+FE0F, U+E0100–U+E01EF). They have no use in
   a module but hiding text from the person reviewing it. A file holding a
   NUL byte is binary: it is not read as text, and it is listed in step 8. A
   file that is neither binary nor valid UTF-8 is refused. HTML comments are
   not refused; each is listed in step 8.
7. **Hash and identity.** The hash (docs/modules.md, "Files and the hash")
   must equal the catalogue's `sha256`. The module must pass the format
   check, and its front matter's `name` and `version` must equal the
   catalogue's.
8. **Show before building.** Print the module's title, version, author,
   licence, source and reviewer; proven-on (or "unproven", in plain words);
   its permissions and the Roles and permissions section; the tables it creates or writes to;
   libraries, each one not found in AGENTS.md §2 marked as needing the
   owner's approval; config keys; every binary file; and every HTML comment
   with its file and line. Then ask to continue. There is no `--yes` for
   this question, and with no terminal to ask on, the install stops here.
9. **Stage.** Move the checked files to `.waffle/modules/<name>@<version>/`,
   and what was installed (catalogue entry, Waffle version, the version it
   replaces) to `.waffle/modules/<name>.json`.
10. **Hand off.** Start `claude` interactively in the app's root with one
    fixed prompt naming the staged path, the name, version and hash, and
    saying: install it by docs/modules.md and AGENTS.md, show before
    building, AGENTS.md wins every disagreement. No permission flags are
    passed. Without `claude` on the PATH, print the prompt for the owner to
    give their own agent.
11. **Record**, when the agent is done (`use` asks, or the owner runs
    `record <name>` later): re-hash the staged copy against what was staged,
    confirm every file under the module's `tests/` is in the app at the same
    path, byte-identical, then run `./tools/test.sh --coverage`. Only when it
    is green, write the `modules.json` entry (name, version, form, source,
    sha256, date), and say to add the CHANGELOG line if the agent did not.

`install` is another name for `use`. `upgrade <name>` is the same pipeline for the newest eligible version above
the installed one, with the installed version in the prompt so the agent
reads the Upgrading entries after it. `record` then replaces the entry.

`verify` checks every `modules.json` entry against the catalogue as it is
now: still listed, with the same source and sha256 (a difference is a loud
warning: a merged version was edited), and not yanked (a warning with the
reason). It changes nothing, and exits 1 when it warned.

## Failure list

Each has a test in `tests/Unit/ModuleTool/` (the PHP) or
`tests/Unit/ModuleCommandTest.php` (the script).

1. The catalogue is fetched from somewhere other than Waffle Iron's fixed URL, or over plain HTTP, or an existing clone's `origin` points elsewhere → refused.
2. The catalogue's history was rewritten → not a fast forward, refused, the old clone kept.
3. `index.json` does not parse, or has an unknown `schema` → refused, with "update tools/module.sh from Waffle" for a newer schema.
4. The newest version is unreviewed or yanked → the newest that is neither is chosen; none → refused, listing each version's reason.
5. A named version is unlisted, unreviewed, yanked, or does not match this app's Waffle version → refused, naming which.
6. A `waffle` constraint uses syntax outside the supported grammar → refused here, and by the format check before listing.
7. The pinned commit is not in the repository, or exists only in a fork → refused.
8. The module is hashed from a working copy, so `.git/` changes the hash → never: it is always a `git archive` export.
9. A `zip` or `file` source is over HTTP, over 10 MB, redirects to HTTP or more than five times → refused.
10. A zip entry has an absolute path, a backslash, a `..` segment, lies outside `<name>/` or is a symlink, or the zip unpacks past 10 MB → refused before anything is written outside scratch.
11. The module contains a symlink or another non-regular file → refused.
12. A file name has a non-printable or non-ASCII character → refused.
13. A text file has a control character, a Unicode format character (Cf), a line or paragraph separator or a variation selector → refused, naming the file, line and code point.
14. An HTML comment is in a module → not refused; shown to the owner with file and line before they confirm.
15. The hash does not match the catalogue → refused; nothing staged.
16. The front matter's `name` or `version` differs from the catalogue entry → refused.
17. The module fails the format check → refused, with the check's message.
18. A library the module needs is not on AGENTS.md §2 → shown as needing approval; the owner decides, the command never adds it.
19. The owner is not asked before the build, or a flag skips the question → impossible: no flag exists, and a run without a terminal stops at step 8.
20. Something from the module or catalogue ends up in a shell command → impossible by construction: module text is only read by PHP, and names and versions are validated before any use.
21. `claude` is not installed → the prompt is printed for another agent; nothing fails.
22. `modules.json` is written while the suite is red, or before it ran → never: `record` runs `./tools/test.sh --coverage` itself and writes only on exit 0.
23. A shipped test was edited or left out during the build → `record` refuses, naming the file.
24. The staged copy changed after the checks → `record` re-hashes it and refuses.
25. The module is already installed → refused; `upgrade` is the way forward.
26. A listed version was edited after this app recorded it → `verify` warns: source or sha256 differs, or it is no longer listed.
27. A recorded version is yanked → `verify` warns with the reason.
28. Run outside a Waffle app, or without Docker → refused by `doctor`'s checks, saying which.
29. Run on a native Windows shell → refused with a pointer to WSL2, as `tools/setup.sh` does.
30. The scratch directory survives a refused install → removed on every exit path.
31. Text from the catalogue (a title, a summary, a yank reason) carries terminal escape sequences → printed with every control character made visible, never acted on by the terminal.
32. The app's Waffle version cannot be told (no semver `VERSION`, no "Started from" line) → refused, saying where it is read from.
33. A file is not valid UTF-8 and holds no NUL byte → refused: it can be neither checked as text nor set aside as binary.
34. A catalogue entry is malformed (unknown form, a repository that is not `https://host/owner/repo`, a short commit, a path with `..`, a bad `sha256`) → refused, naming the field.
35. `tools/setup.sh` installs `iron`, or runs anything it fetches → never: it prints Waffle Iron's install line when `iron` is missing, and that is all.

## Not in this version

- **Other catalogues** (Homebrew's `tap`). The catalogue URL is fixed. A
  second catalogue means trusting whoever runs it, and that needs its own
  design.
- **Uninstall.** After install the code is the app's (docs/modules.md);
  removing it is ordinary work, not a command.
