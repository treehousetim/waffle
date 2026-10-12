<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The `settings` key/value table and its reference rows (docs/settings.md).
 *
 * Rows carry their own presentation metadata so the Settings page renders
 * itself from the data, and so the save allowlist is `settings_editable`
 * rather than a list kept in code. Adding a setting is therefore a migration,
 * not a code change.
 *
 * Reference data is inserted here, not in a seeder: seeds are local dev
 * fixtures and never run in production.
 *
 * Configuration, never secrets. A secret lives in Doppler and nowhere else.
 */
final class CreateSettingsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE `settings` (
              `settings_key` VARCHAR(64) NOT NULL,
              `settings_value` TEXT,
              `settings_label` VARCHAR(128) NOT NULL,
              `settings_description` VARCHAR(255) NOT NULL DEFAULT '',
              `settings_type_id` INT UNSIGNED NOT NULL,
              `settings_options` TEXT,
              `settings_group` VARCHAR(64) NOT NULL,
              `settings_editable` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
              `settings_sort` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              `settings_updated_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`settings_key`),
              KEY `settings_group_sort` (`settings_group`,`settings_sort`),
              CONSTRAINT `settings_type_fk` FOREIGN KEY (`settings_type_id`) REFERENCES `types` (`types_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $rows = [
            // key, value, label, description, type, options, group, editable, sort
            ['app_name', 'Waffle', 'Application name', 'Shown in the page title and the sidebar.', 'string', null, 'general', 1, 10],
            ['display_timezone', 'UTC', 'Display timezone', 'Timestamps are stored in UTC and converted to this zone for display.', 'string', null, 'general', 1, 20],
            ['page_size', '25', 'Rows per page', 'How many rows a list shows before it pages.', 'int', null, 'display', 1, 10],
            ['date_style', 'relative', 'Date style', 'How dates are written in lists.', 'select', '["relative","absolute"]', 'display', 1, 20],

            // Not editable from the UI: shown for reference.
            ['schema_owner', 'waffle', 'Schema owner', 'Informational. Changing this does not move the database.', 'string', null, 'general', 0, 90],
        ];

        $pdo = $this->getAdapter()->getConnection();
        $typeId = $pdo->prepare("SELECT types_id FROM types WHERE types_group = 'setting_type' AND types_name = ?");

        foreach ($rows as [$key, $value, $label, $description, $type, $options, $group, $editable, $sort]) {
            $typeId->execute([$type]);

            $this->table('settings')->insert([
                'settings_key' => $key,
                'settings_value' => $value,
                'settings_label' => $label,
                'settings_description' => $description,
                'settings_type_id' => (int) $typeId->fetchColumn(),
                'settings_options' => $options,
                'settings_group' => $group,
                'settings_editable' => $editable,
                'settings_sort' => $sort,
            ])->saveData();
        }
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `settings`');
    }
}
