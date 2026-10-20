<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The media library (docs/content.md, "Media"): every image, described once
 * and used by any record. The file itself is behind StoragePort. An image
 * becomes public (`media_public_at`) when a record that uses it is first
 * published, and stays so, so a site's old release never loses a picture.
 */
final class CreateMediaTable extends AbstractMigration
{
    public function up(): void
    {
        $sort = 0;
        $rows = [];

        // App\Domain\Content\Licence
        foreach (['own' => 'Our own', 'permission' => 'Used with permission', 'licensed' => 'Licensed, credit required'] as $name => $label) {
            $sort += 10;
            $rows[] = ['types_group' => 'licence', 'types_name' => $name, 'types_label' => $label, 'types_sort' => $sort];
        }

        $this->table('types')->insert($rows)->saveData();

        $this->execute(<<<'SQL'
            CREATE TABLE `media` (
              `media_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `media_storage_key` VARCHAR(255) NOT NULL,
              `media_sha256` CHAR(64) NOT NULL,
              `media_mime` VARCHAR(50) NOT NULL,
              `media_width` INT UNSIGNED NOT NULL,
              `media_height` INT UNSIGNED NOT NULL,
              `media_bytes` INT UNSIGNED NOT NULL,
              `media_alt` VARCHAR(300) NOT NULL DEFAULT '',
              `media_caption` VARCHAR(300) DEFAULT NULL,
              `media_credit_name` VARCHAR(150) DEFAULT NULL,
              `media_credit_url` VARCHAR(500) DEFAULT NULL,
              `media_licence_id` INT UNSIGNED NOT NULL,
              `media_public_at` DATETIME DEFAULT NULL,
              `media_created_by` INT UNSIGNED DEFAULT NULL,
              `media_created_at` DATETIME NOT NULL,
              PRIMARY KEY (`media_id`),
              UNIQUE KEY `media_storage_key` (`media_storage_key`),
              UNIQUE KEY `media_sha256` (`media_sha256`),
              CONSTRAINT `media_licence_fk` FOREIGN KEY (`media_licence_id`) REFERENCES `types` (`types_id`),
              CONSTRAINT `media_created_by_fk` FOREIGN KEY (`media_created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `media`');
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'licence'");
    }
}
