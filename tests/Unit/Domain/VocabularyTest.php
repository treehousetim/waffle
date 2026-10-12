<?php

declare(strict_types=1);

use App\Domain\Settings\SettingType;
use Tests\TestCase;

/*
 * Every vocabulary lives twice: as a backed enum in Domain/ (the source of
 * truth) and as rows in the `types` table (what status and type columns are
 * foreign keys into). This keeps the two in step: adding a case without the
 * migration that inserts its row fails here, and so does the reverse.
 *
 * ADDING A VOCABULARY: one line in the dataset below, and a migration inserting
 * its group into `types`.
 */

it('has exactly one types row per enum case', function (string $enum, string $group): void {
    $statement = TestCase::pdo()->prepare('SELECT types_name FROM types WHERE types_group = ? ORDER BY types_name');
    $statement->execute([$group]);

    $rows = $statement->fetchAll(PDO::FETCH_COLUMN);
    $cases = array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases());
    sort($cases);

    expect($rows)->toBe($cases);
})->with([
    'setting types' => [SettingType::class, 'setting_type'],
]);
