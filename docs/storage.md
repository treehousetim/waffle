# Storage

Files the app keeps: uploads, photos, and the media of the content model
(docs/content-model.md). Every file goes through `StoragePort`
(`src/app/Domain/Ports/StoragePort.php`), by a key the app makes
(`photos/7-3f9a.jpg`), never a name from a request.

- **On the local disk today**: `LocalDiskStorage`, under `/var/www/storage`,
  which every stack mounts from the `storage_data` volume (php and worker), so
  a deploy never touches the files. The image creates the mount point owned
  by `www-data`, the user php-fpm runs as, so a new volume starts out
  writable; a volume made before that needs
  `docker compose exec php chown www-data:www-data /var/www/storage` once. A file is written to a temporary name and
  renamed into place, so nobody reads half of one.
- **Object storage later**: an S3-compatible adapter (DigitalOcean Spaces)
  replaces it behind the same port when a project needs one; no caller
  changes. Its client library needs approval first (AGENTS.md §2).
- **Tests** bind `FakeStorage`, in memory (`tests/Support/Overrides/storage.php`).
- **Not backed up yet.** The nightly backup is the database only
  (`tools/backup-prod-db.sh`); the `storage_data` volume is not in it.
  OPEN-QUESTIONS.md has the decision.

## Failure list

1. A key that could leave the storage root (`../`, an absolute path) reaches the disk.
2. A file that cannot be stored (a file or directory in the way, or a volume the app's user cannot write to) fails with a PHP warning, or is reported stored, or leaves half a file, instead of an exception that says what.
