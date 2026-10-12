<?php

declare(strict_types=1);

use Tests\TestCase;

/**
 * These tests issue DDL, which MariaDB commits implicitly. That would end the
 * transaction a Feature or Unit test runs inside and silently turn its rollback
 * into a no-op — so the Schema suite is bound to a base class WITHOUT the
 * transaction hooks, and everything it creates it drops itself.
 */
const PROBE_TABLE = 'schema_probe';

afterEach(function (): void {
    TestCase::pdo()->exec('DROP TABLE IF EXISTS ' . PROBE_TABLE);
});

it('runs outside the suite transaction, which is what makes DDL safe here', function (): void {
    expect(TestCase::pdo()->inTransaction())->toBeFalse();
});

it('can create a table, use it, and drop it without leaking it', function (): void {
    $pdo = TestCase::pdo();

    $pdo->exec(
        'CREATE TABLE ' . PROBE_TABLE . ' (
            probe_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            probe_label VARCHAR(32) NOT NULL,
            PRIMARY KEY (probe_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $insert = $pdo->prepare('INSERT INTO ' . PROBE_TABLE . ' (probe_label) VALUES (?)');
    $insert->execute(['hello']);

    $row = $pdo->query('SELECT probe_label FROM ' . PROBE_TABLE)->fetch();

    expect($row['probe_label'])->toBe('hello');

    $pdo->exec('DROP TABLE ' . PROBE_TABLE);

    expect(tableExists(PROBE_TABLE))->toBeFalse();
});

it('leaves no probe table behind from the previous test', function (): void {
    expect(tableExists(PROBE_TABLE))->toBeFalse();
});

it('stores every application table as InnoDB with utf8mb4_unicode_ci', function (): void {
    $rows = TestCase::pdo()->query(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchAll();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect($row['ENGINE'])->toBe('InnoDB', "{$row['TABLE_NAME']} is not InnoDB")
            ->and($row['TABLE_COLLATION'])->toBe('utf8mb4_unicode_ci', "{$row['TABLE_NAME']} has the wrong collation");
    }
});

it('prefixes every column with its table name', function (): void {
    // The exceptions, and why:
    //   users*    Delight\Auth's own schema, which we do not control
    //   phinxlog  Phinx's own bookkeeping table
    $exempt = static fn (string $table): bool => str_starts_with($table, 'users') || $table === 'phinxlog';

    $rows = TestCase::pdo()->query(
        "SELECT TABLE_NAME, COLUMN_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
          ORDER BY TABLE_NAME, ORDINAL_POSITION"
    )->fetchAll();

    $offenders = [];

    foreach ($rows as $row) {
        if ($exempt($row['TABLE_NAME'])) {
            continue;
        }

        if (!str_starts_with($row['COLUMN_NAME'], $row['TABLE_NAME'] . '_')) {
            $offenders[] = "{$row['TABLE_NAME']}.{$row['COLUMN_NAME']}";
        }
    }

    expect($offenders)->toBe([], 'columns not prefixed with their table name: ' . implode(', ', $offenders));
});

it('has the timezone tables CONVERT_TZ needs for named zones', function (): void {
    // Display queries convert UTC to a named zone. Without MariaDB's timezone
    // tables loaded, CONVERT_TZ silently returns NULL instead of failing — so
    // this is checked here rather than discovered in production.
    $row = TestCase::pdo()->query(
        "SELECT CONVERT_TZ('2026-01-15 18:30:00', '+00:00', 'America/Denver') AS converted"
    )->fetch();

    expect($row['converted'])->not->toBeNull()
        ->and($row['converted'])->toBe('2026-01-15 11:30:00');
});

function tableExists(string $table): bool
{
    $statement = TestCase::pdo()->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);

    return (int) $statement->fetchColumn() > 0;
}
