<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Forms (docs/forms.md): the sites that send them, with their keys kept only
 * as hashes, and what they send. The `form` types group holds one row per
 * declared form; `contact` is the skeleton's.
 */
final class CreateFormsTables extends AbstractMigration
{
    public function up(): void
    {
        $groups = [
            // App\Domain\Forms\SiteStatus
            'site_status' => ['active' => 'Active', 'disabled' => 'Disabled'],
            // App\Domain\Forms\SubmissionStatus
            'submission_status' => ['new' => 'New', 'handled' => 'Handled', 'spam' => 'Spam'],
            // Every form:<name> definition
            'form' => ['contact' => 'Contact'],
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
            CREATE TABLE `sites` (
              `sites_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `sites_slug` VARCHAR(100) NOT NULL,
              `sites_name` VARCHAR(100) NOT NULL,
              `sites_origin` VARCHAR(255) NOT NULL,
              `sites_key_hash` CHAR(64) DEFAULT NULL,
              `sites_key_issued_at` DATETIME DEFAULT NULL,
              `sites_status_id` INT UNSIGNED NOT NULL,
              `sites_created_at` DATETIME NOT NULL,
              `sites_updated_at` DATETIME NOT NULL,
              PRIMARY KEY (`sites_id`),
              UNIQUE KEY `sites_slug` (`sites_slug`),
              UNIQUE KEY `sites_key_hash` (`sites_key_hash`),
              CONSTRAINT `sites_status_fk` FOREIGN KEY (`sites_status_id`) REFERENCES `types` (`types_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `form_submissions` (
              `form_submissions_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `form_submissions_form_id` INT UNSIGNED NOT NULL,
              `form_submissions_site_id` INT UNSIGNED NOT NULL,
              `form_submissions_payload` JSON NOT NULL,
              `form_submissions_status_id` INT UNSIGNED NOT NULL,
              `form_submissions_ip_hash` CHAR(64) NOT NULL,
              `form_submissions_created_at` DATETIME NOT NULL,
              `form_submissions_decided_by` INT UNSIGNED DEFAULT NULL,
              `form_submissions_decided_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`form_submissions_id`),
              KEY `form_submissions_status_created` (`form_submissions_status_id`, `form_submissions_created_at`),
              KEY `form_submissions_ip_created` (`form_submissions_ip_hash`, `form_submissions_created_at`),
              KEY `form_submissions_created` (`form_submissions_created_at`),
              CONSTRAINT `form_submissions_form_fk` FOREIGN KEY (`form_submissions_form_id`) REFERENCES `types` (`types_id`),
              CONSTRAINT `form_submissions_site_fk` FOREIGN KEY (`form_submissions_site_id`) REFERENCES `sites` (`sites_id`),
              CONSTRAINT `form_submissions_status_fk` FOREIGN KEY (`form_submissions_status_id`) REFERENCES `types` (`types_id`),
              CONSTRAINT `form_submissions_decided_by_fk` FOREIGN KEY (`form_submissions_decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $type = $this->getAdapter()->getConnection()->query("SELECT types_id FROM types WHERE types_group = 'setting_type' AND types_name = 'int'")->fetchColumn();
        $this->table('settings')->insert([
            'settings_key' => 'form_retention_days',
            'settings_value' => '180',
            'settings_label' => 'Keep form submissions for (days)',
            'settings_description' => 'Submissions are personal data; older ones are deleted nightly (docs/forms.md).',
            'settings_type_id' => $type,
            'settings_options' => null,
            'settings_group' => 'forms',
            'settings_editable' => 1,
            'settings_sort' => 10,
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `form_submissions`');
        $this->execute('DROP TABLE IF EXISTS `sites`');
        $this->execute("DELETE FROM `settings` WHERE `settings_key` = 'form_retention_days'");
        $this->execute("DELETE FROM `types` WHERE `types_group` IN ('site_status', 'submission_status', 'form')");
    }
}
