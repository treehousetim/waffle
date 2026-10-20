<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pages (docs/content.md, "Blocks"): the record type whose body is blocks,
 * with the same three tables as every record type. `pages_body` holds the
 * blocks as JSON, checked by Domain\Content\Blocks on every save.
 *
 * `pages_slugs_slug` is unique across the table, history included (6.).
 */
final class CreatePagesTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE `pages` (
              `pages_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `pages_slug` VARCHAR(100) NOT NULL,
              `pages_status_id` INT UNSIGNED NOT NULL,
              `pages_title` VARCHAR(150) NOT NULL,
              `pages_summary` VARCHAR(300) NOT NULL DEFAULT '',
              `pages_body` MEDIUMTEXT NOT NULL,
              `pages_seo_title` VARCHAR(60) DEFAULT NULL,
              `pages_seo_description` VARCHAR(160) DEFAULT NULL,
              `pages_published_at` DATETIME DEFAULT NULL,
              `pages_retired_at` DATETIME DEFAULT NULL,
              `pages_content_changed_at` DATETIME NOT NULL,
              `pages_created_at` DATETIME NOT NULL,
              `pages_updated_at` DATETIME NOT NULL,
              PRIMARY KEY (`pages_id`),
              UNIQUE KEY `pages_slug` (`pages_slug`),
              KEY `pages_status_updated` (`pages_status_id`, `pages_updated_at`),
              CONSTRAINT `pages_status_fk` FOREIGN KEY (`pages_status_id`) REFERENCES `types` (`types_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `pages_revisions` (
              `pages_revisions_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `pages_revisions_page_id` INT UNSIGNED NOT NULL,
              `pages_revisions_content` JSON NOT NULL,
              `pages_revisions_published_by` INT UNSIGNED NOT NULL,
              `pages_revisions_published_at` DATETIME NOT NULL,
              PRIMARY KEY (`pages_revisions_id`),
              KEY `pages_revisions_page` (`pages_revisions_page_id`, `pages_revisions_id`),
              CONSTRAINT `pages_revisions_page_fk` FOREIGN KEY (`pages_revisions_page_id`) REFERENCES `pages` (`pages_id`) ON DELETE CASCADE,
              CONSTRAINT `pages_revisions_by_fk` FOREIGN KEY (`pages_revisions_published_by`) REFERENCES `users` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `pages_slugs` (
              `pages_slugs_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `pages_slugs_page_id` INT UNSIGNED NOT NULL,
              `pages_slugs_slug` VARCHAR(100) NOT NULL,
              `pages_slugs_created_at` DATETIME NOT NULL,
              `pages_slugs_replaced_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`pages_slugs_id`),
              UNIQUE KEY `pages_slugs_slug` (`pages_slugs_slug`),
              KEY `pages_slugs_page` (`pages_slugs_page_id`),
              CONSTRAINT `pages_slugs_page_fk` FOREIGN KEY (`pages_slugs_page_id`) REFERENCES `pages` (`pages_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `pages_slugs`');
        $this->execute('DROP TABLE IF EXISTS `pages_revisions`');
        $this->execute('DROP TABLE IF EXISTS `pages`');
    }
}
