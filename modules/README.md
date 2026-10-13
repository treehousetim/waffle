# modules/

Drop a module here to check it against Waffle's module format
([docs/modules.md](../docs/modules.md)) before you install or publish it: a
directory, a `.zip` of one, or a single `<name>.module.md`. Then run

```bash
./tools/test.sh tests/Unit/ModulesTest.php
```

Waffle ships no modules of its own. They are listed in
[Waffle Iron](https://github.com/treehousetim/waffle-iron); the first is
[short links](https://github.com/treehousetim/waffle-short-links). This
directory is never renamed when a project is started from Waffle.
