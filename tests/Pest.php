<?php

declare(strict_types=1);

use Tests\SchemaTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
|
| Feature and Unit tests run inside a database transaction that is rolled back
| afterwards, so the schema and its reference data stay clean between runs.
|
| Schema tests do NOT get those hooks: MariaDB commits implicitly on DDL, which
| would silently discard the surrounding transaction and leave whatever the
| test created behind. Those tests clean up after themselves instead.
|
| Arch tests need no test case at all.
|
*/

uses(TestCase::class)
    ->beforeEach(function (): void {
        $this->beginDatabaseTransaction();
    })
    ->afterEach(function (): void {
        $this->rollBackDatabaseTransaction();
    })
    ->in('Unit', 'Feature');

uses(SchemaTestCase::class)->in('Schema');

/*
|--------------------------------------------------------------------------
| Groups, so a partial run can follow a change
|--------------------------------------------------------------------------
|
| The full suite grows slow enough that nobody sits through it after every
| edit. `./tools/test.sh --group=settings` runs one slice in a second or two.
|
| The groups ARE the slices the codebase already declares — one file each in
| src/config/container/, src/config/routes/ and tests/Support/Overrides/ — so
| a feature that lands as a slice has its tests in the matching group without
| anybody inventing a taxonomy. `core` is the platform underneath all of them:
| auth, middleware, the responder, the repositories nothing else owns.
|
| Bound by path here rather than annotated on each file, so the whole mapping
| is one table you can read. Groups are additive, which is deliberate: a test
| can sit in its slice and in `e2e`, and runs under either.
|
| tests/Arch/ArchGroupsTest.php asserts every test file lands in at least one
| group, that every path named here exists, and that every container slice has
| a group of the same name.
|
| ADDING A SLICE: one uses()->group('<slice>')->in(...) call below, naming its
| unit-test directory and its feature test, and one line in
| tools/changed-groups.sh.
|
*/

uses()->group('core')->in(
    'Unit/Controllers',
    'Unit/Domain/Auth',
    'Unit/Domain/Status',
    'Unit/Domain/VocabularyTest.php',
    'Unit/Http',
    'Unit/Libraries',
    'Unit/Middleware',
    'Unit/Models/TypeRepositoryTest.php',
    'Unit/Models/UserRepositoryTest.php',
    'Unit/Models/HealthRepositoryTest.php',
    'Unit/ContainerWiringTest.php',
    'Unit/NginxHeadersTest.php',
    'Unit/ReleaseTest.php',
    'Unit/DeployScriptsTest.php',
    'Unit/CreateProjectTest.php',
    'Feature/AuthTest.php',
    'Feature/HelloTest.php',
    'Feature/CronTest.php',
    'Feature/CrossSiteTest.php',
    'Feature/SpaFallbackTest.php',
    'Feature/AccessGridTest.php',
);

// The settings slice (docs/settings.md): the worked example of a slice.
uses()->group('settings')->in(
    'Unit/Domain/Settings',
    'Unit/Models/SettingRepositoryTest.php',
    'Feature/SettingsTest.php',
);

// End-to-end tests: a whole feature through the router, ending in an artifact
// under coverage/e2e/ (see tests/Support/E2EArtifact.php).
uses()->group('e2e')->in(
    'Feature/E2E',
);

uses()->group('schema')->in(
    'Schema',
);

uses()->group('arch')->in(
    'Arch',
);
