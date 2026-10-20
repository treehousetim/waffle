<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Content (docs/content.md): the publish statuses, and the first record type,
 * `posts`, with its revisions and slug history. Every later record type has
 * the same three tables with its own name (docs/content-model.md).
 *
 * `posts_slugs_slug` is unique across the table, history included, so no slug
 * is ever given to a second post (6.).
 */
final class CreateContentTables extends AbstractMigration
{
    public function up(): void
    {
        $sort = 0;
        $rows = [];

        // App\Domain\Content\PublishStatus
        foreach (['draft' => 'Draft', 'published' => 'Published', 'retired' => 'Retired'] as $name => $label) {
            $sort += 10;
            $rows[] = ['types_group' => 'publish_status', 'types_name' => $name, 'types_label' => $label, 'types_sort' => $sort];
        }

        $this->table('types')->insert($rows)->saveData();

        $this->execute(<<<'SQL'
            CREATE TABLE `posts` (
              `posts_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `posts_slug` VARCHAR(100) NOT NULL,
              `posts_status_id` INT UNSIGNED NOT NULL,
              `posts_title` VARCHAR(150) NOT NULL,
              `posts_summary` VARCHAR(300) NOT NULL DEFAULT '',
              `posts_body` MEDIUMTEXT NOT NULL,
              `posts_seo_title` VARCHAR(60) DEFAULT NULL,
              `posts_seo_description` VARCHAR(160) DEFAULT NULL,
              `posts_published_at` DATETIME DEFAULT NULL,
              `posts_retired_at` DATETIME DEFAULT NULL,
              `posts_content_changed_at` DATETIME NOT NULL,
              `posts_created_at` DATETIME NOT NULL,
              `posts_updated_at` DATETIME NOT NULL,
              PRIMARY KEY (`posts_id`),
              UNIQUE KEY `posts_slug` (`posts_slug`),
              KEY `posts_status_updated` (`posts_status_id`, `posts_updated_at`),
              CONSTRAINT `posts_status_fk` FOREIGN KEY (`posts_status_id`) REFERENCES `types` (`types_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `posts_revisions` (
              `posts_revisions_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `posts_revisions_post_id` INT UNSIGNED NOT NULL,
              `posts_revisions_content` JSON NOT NULL,
              `posts_revisions_published_by` INT UNSIGNED NOT NULL,
              `posts_revisions_published_at` DATETIME NOT NULL,
              PRIMARY KEY (`posts_revisions_id`),
              KEY `posts_revisions_post` (`posts_revisions_post_id`, `posts_revisions_id`),
              CONSTRAINT `posts_revisions_post_fk` FOREIGN KEY (`posts_revisions_post_id`) REFERENCES `posts` (`posts_id`) ON DELETE CASCADE,
              CONSTRAINT `posts_revisions_by_fk` FOREIGN KEY (`posts_revisions_published_by`) REFERENCES `users` (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `posts_slugs` (
              `posts_slugs_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `posts_slugs_post_id` INT UNSIGNED NOT NULL,
              `posts_slugs_slug` VARCHAR(100) NOT NULL,
              `posts_slugs_created_at` DATETIME NOT NULL,
              `posts_slugs_replaced_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`posts_slugs_id`),
              UNIQUE KEY `posts_slugs_slug` (`posts_slugs_slug`),
              KEY `posts_slugs_post` (`posts_slugs_post_id`),
              CONSTRAINT `posts_slugs_post_fk` FOREIGN KEY (`posts_slugs_post_id`) REFERENCES `posts` (`posts_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `posts_slugs`');
        $this->execute('DROP TABLE IF EXISTS `posts_revisions`');
        $this->execute('DROP TABLE IF EXISTS `posts`');
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'publish_status'");
    }
}
