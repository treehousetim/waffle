<?php

declare(strict_types=1);

use App\Domain\Content\PublishStatus;
use App\Domain\Invitations\InvitationStatus;
use App\Domain\Queue\JobStatus;
use App\Domain\Queue\RunOutcome;
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
    'job statuses' => [JobStatus::class, 'job_status'],
    'job run outcomes' => [RunOutcome::class, 'job_run_outcome'],
    'publish statuses' => [PublishStatus::class, 'publish_status'],
    'invitation statuses' => [InvitationStatus::class, 'invitation_status'],
]);

it('has exactly one `job` types row per registered job handler (docs/queue.md, 11)', function (): void {
    // The production definitions, not the test container's: the test job is
    // registered only there, and its row only inside the tests that use it.
    /** @var array<string,mixed> $definitions */
    $definitions = require dirname(__DIR__, 3) . '/src/config/container.php';
    $registered = $definitions['queue.job_types'];
    sort($registered);

    $rows = TestCase::pdo()->query("SELECT types_name FROM types WHERE types_group = 'job' ORDER BY types_name")
        ->fetchAll(PDO::FETCH_COLUMN);

    expect($rows)->toBe($registered);
});
