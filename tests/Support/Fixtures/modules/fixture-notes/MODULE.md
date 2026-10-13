---
name: fixture-notes
title: Fixture notes
version: 1.0.0
summary: The smallest module the format allows, so ModulesTest has something to check when modules/ is empty. Never install it.
waffle: >=0.1 <1.0
proven-on: none
author: treehousetim
license: MIT
permissions: fixture-notes-view
libraries: none
config: none
---

# Fixture notes

## Purpose

A test fixture for `tests/Unit/ModulesTest.php`, not a feature: one table of
notes that signed-in people can read.

## Roles and permissions

- `fixture-notes-view`: the owner, an administrator and a user may read notes.
- Anyone not signed in gets `401`.

## Data model and migrations

```sql
CREATE TABLE fixture_notes (
  fixture_notes_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fixture_notes_text VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Failure list

1. Someone not signed in reads a note.
2. A note longer than 255 characters is cut short instead of refused.

## Installation

Do not install it.
