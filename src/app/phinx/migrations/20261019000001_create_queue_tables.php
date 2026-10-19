<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The queue's tables (docs/queue.md).
 *
 * `jobs` holds every job until it is finished and pruned; `job_runs` one row
 * per attempt, so a failing job's history is visible. The `job_status` and
 * `job_run_outcome` groups mirror App\Domain\Queue\JobStatus and RunOutcome. The `job` group (which job a row is) is
 * empty here: each slice that adds a job inserts its own row in its own
 * migration, and tests/Unit/Domain/VocabularyTest.php keeps those rows and the
 * registered handlers in step.
 *
 * `jobs_pending_key` is the coalescing key while a job is pending and NULL
 * from the moment it is claimed, so its unique index allows one pending job
 * per key and never blocks a new one behind a running or finished job.
 */
final class CreateQueueTables extends AbstractMigration
{
    public function up(): void
    {
        $groups = [
            // App\Domain\Queue\JobStatus
            'job_status' => ['pending' => 'Waiting', 'running' => 'Running', 'done' => 'Done', 'dead' => 'Failed'],
            // App\Domain\Queue\RunOutcome
            'job_run_outcome' => [
                'done' => 'Done',
                'failed' => 'Failed, will retry',
                'dead' => 'Failed, gave up',
                'abandoned' => 'Worker stopped mid-run',
            ],
        ];
        $rows = [];

        foreach ($groups as $group => $names) {
            $sort = 0;

            foreach ($names as $name => $label) {
                $sort += 10;
                $rows[] = ['types_group' => $group, 'types_name' => $name, 'types_label' => $label, 'types_sort' => $sort];
            }
        }

        $this->table('types')->insert($rows)->saveData();

        $this->execute(<<<'SQL'
            CREATE TABLE `jobs` (
              `jobs_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `jobs_type_id` INT UNSIGNED NOT NULL,
              `jobs_payload` JSON NOT NULL,
              `jobs_unique_key` VARCHAR(191) DEFAULT NULL,
              `jobs_pending_key` VARCHAR(191) DEFAULT NULL,
              `jobs_status_id` INT UNSIGNED NOT NULL,
              `jobs_run_after` DATETIME NOT NULL,
              `jobs_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
              `jobs_max_attempts` TINYINT UNSIGNED NOT NULL,
              `jobs_locked_by` VARCHAR(100) DEFAULT NULL,
              `jobs_lease_until` DATETIME DEFAULT NULL,
              `jobs_last_error` TEXT,
              `jobs_created_at` DATETIME NOT NULL,
              `jobs_finished_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`jobs_id`),
              UNIQUE KEY `jobs_pending_key` (`jobs_pending_key`),
              KEY `jobs_status_run_after` (`jobs_status_id`,`jobs_run_after`),
              KEY `jobs_status_finished` (`jobs_status_id`,`jobs_finished_at`),
              KEY `jobs_status_lease` (`jobs_status_id`,`jobs_lease_until`),
              CONSTRAINT `jobs_type_fk` FOREIGN KEY (`jobs_type_id`) REFERENCES `types` (`types_id`),
              CONSTRAINT `jobs_status_fk` FOREIGN KEY (`jobs_status_id`) REFERENCES `types` (`types_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `job_runs` (
              `job_runs_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `job_runs_job_id` BIGINT UNSIGNED NOT NULL,
              `job_runs_worker` VARCHAR(100) NOT NULL,
              `job_runs_attempt` TINYINT UNSIGNED NOT NULL,
              `job_runs_outcome_id` INT UNSIGNED NOT NULL,
              `job_runs_error` TEXT,
              `job_runs_started_at` DATETIME NOT NULL,
              `job_runs_finished_at` DATETIME NOT NULL,
              PRIMARY KEY (`job_runs_id`),
              KEY `job_runs_job` (`job_runs_job_id`),
              CONSTRAINT `job_runs_job_fk` FOREIGN KEY (`job_runs_job_id`) REFERENCES `jobs` (`jobs_id`) ON DELETE CASCADE,
              CONSTRAINT `job_runs_outcome_fk` FOREIGN KEY (`job_runs_outcome_id`) REFERENCES `types` (`types_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `job_runs`');
        $this->execute('DROP TABLE IF EXISTS `jobs`');
        $this->execute("DELETE FROM `types` WHERE `types_group` IN ('job_status', 'job_run_outcome')");
    }
}
