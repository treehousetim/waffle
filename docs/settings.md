# Settings

Application configuration that people may change without a release: a
key/value table whose rows describe how to render and validate themselves.
**Never secrets** — those live in Doppler and nowhere else.

This is also the worked example of a feature slice. Every piece of it is in
the place a new slice's pieces go:

| Piece | File |
| --- | --- |
| Vocabulary (enum + `types` rows) | `src/app/Domain/Settings/SettingType.php`, group `setting_type` |
| Domain error | `src/app/Domain/Settings/InvalidSetting.php` |
| Table + reference rows | `src/app/phinx/migrations/20261012000003_create_settings_table.php` |
| Entity + repository | `src/app/Models/Setting.php`, `SettingRepository.php` |
| Controller | `src/app/Controllers/SettingController.php` |
| Wiring | `src/config/container/settings.php`, `src/config/routes/settings.php` |
| Permissions | `settings-view`, `settings-edit` (docs/access-levels.md) |
| Tests | `tests/Unit/Domain/Settings/`, `tests/Unit/Models/SettingRepositoryTest.php`, `tests/Feature/SettingsTest.php` — group `settings` |
| Page | `src/frontend/src/views/SettingsView.vue`, route `/settings` |

## The table

| Column | Meaning |
| --- | --- |
| `settings_key` | Primary key, snake_case. |
| `settings_value` | Text; each type has one stored form (below). |
| `settings_label`, `settings_description` | What the page shows. |
| `settings_type_id` | FK into `types` (`setting_type`): `string`, `int`, `bool`, `select`. |
| `settings_options` | JSON list of allowed values, for `select`. |
| `settings_group`, `settings_sort` | Where on the page, and in what order. |
| `settings_editable` | 0 for rows shown for reference only. This is the save allowlist. |
| `settings_updated_at` | UTC, set on every save. |

## The API

- `GET /api/settings` (`settings-view`) → `{ settings: [...] }`, by group then sort.
- `PUT /api/settings` (`settings-edit`) with `{ values: { key: value, ... } }` →
  the whole list again. **All or nothing:** every value is checked before any
  is written.

## Failure list

Written before the code; each has a test.

1. A value for a key that does not exist → 422 "There is no setting called '…'."
2. A value for a row that is not editable → 422 "… cannot be changed here."
3. A value that is not a single scalar (a list, an object, null) → 422 "… must be a single value."
4. Text over 1000 characters → 422.
5. An `int` that is not a whole number (letters, a decimal, a boolean, empty) → 422.
6. A `bool` that is not one of true/false/1/0/on/off/yes/no → 422.
7. A `select` value not among its options → 422 "… must be one of: …".
8. One bad value among several good ones → 422 and **nothing** written.
9. A body without `values`, or with an empty or non-map `values` → 422 naming the shape.
10. A level without `settings-view` reads, or without `settings-edit` writes → 403 (AccessGridTest).

## Adding a setting

A new migration (never an edit to the one above) inserting the row, with its
type id looked up from `types`. Then read it where it is needed through
`SettingRepository::find()`. If a feature reads one on every request, give
that feature a small typed accessor rather than scattering string keys.
