# modules/

Drop a module here to check it against Waffle's module format
([docs/modules.md](../docs/modules.md)) before you install or publish it: a
directory, a `.zip` of one, or a single `<name>.module.md`. Each must be named
for the module's `name:`, so clone a module's repository under that name:

```bash
git clone https://github.com/treehousetim/waffle-short-links modules/short-links
./tools/test.sh tests/Unit/ModulesTest.php
```

Like everything `tools/test.sh` runs, the check starts the app's Docker stack.

Waffle ships no modules of its own. They are listed in
[Waffle Iron](https://github.com/treehousetim/waffle-iron); the first is
[short links](https://github.com/treehousetim/waffle-short-links). The project
rename (`tools/rename-project.sh`) never touches anything in this directory.
