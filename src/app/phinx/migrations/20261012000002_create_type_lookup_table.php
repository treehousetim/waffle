<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The shared status/type lookup table.
 *
 * Every status or type column is a foreign key into here rather than a free
 * string, so a typo is a constraint violation instead of a row nobody notices.
 * `TypeRepository` memoises name -> id per group and is shared through the
 * container; no other class builds its own instance.
 *
 * The domain still reasons in enums — these rows are the database's side of
 * that contract, plus the labels the UI renders. Adding a vocabulary, or a
 * case to one, is a NEW migration inserting rows here (never an edit to this
 * one), and tests/Unit/Domain/VocabularyTest.php fails until enum and rows
 * agree.
 */
final class CreateTypeLookupTable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE `types` (
              `types_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `types_group` VARCHAR(48) NOT NULL,
              `types_name` VARCHAR(48) NOT NULL,
              `types_label` VARCHAR(96) NOT NULL,
              `types_sort` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`types_id`),
              UNIQUE KEY `types_group_name` (`types_group`,`types_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $groups = [
            // App\Domain\Settings\SettingType
            'setting_type' => [
                'string' => 'Text',
                'int' => 'Whole number',
                'bool' => 'On or off',
                'select' => 'One of a list',
            ],
        ];

        $rows = [];

        foreach ($groups as $group => $names) {
            $sort = 0;

            foreach ($names as $name => $label) {
                $sort += 10;
                $rows[] = [
                    'types_group' => $group,
                    'types_name' => $name,
                    'types_label' => $label,
                    'types_sort' => $sort,
                ];
            }
        }

        $this->table('types')->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `types`');
    }
}
