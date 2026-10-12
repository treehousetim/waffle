<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Delight\Auth's own schema, from vendor/delight-im/auth/Database/MySQL.sql.
 *
 * Two deliberate departures from the file it was copied from:
 *
 *  1. ENGINE=InnoDB, not MyISAM. The app is InnoDB-only, and MyISAM tables are not
 *     transactional — the test suite wraps every test in a transaction and
 *     rolls it back, which MyISAM would silently ignore, leaking users between
 *     tests.
 *  2. TEXT columns lost their `DEFAULT NULL`, which is a MariaDB extension.
 *
 * Column names here are NOT prefixed with the table name. These are the
 * library's tables, not ours, and are the one documented exception to that
 * convention.
 */
final class CreateAuthTables extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
            CREATE TABLE `users` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `email` VARCHAR(249) COLLATE utf8mb4_unicode_ci NOT NULL,
              `password` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `username` VARCHAR(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              `status` TINYINT UNSIGNED NOT NULL DEFAULT '0',
              `verified` TINYINT UNSIGNED NOT NULL DEFAULT '0',
              `resettable` TINYINT UNSIGNED NOT NULL DEFAULT '1',
              `roles_mask` INT UNSIGNED NOT NULL DEFAULT '0',
              `registered` INT UNSIGNED NOT NULL,
              `last_login` INT UNSIGNED DEFAULT NULL,
              `force_logout` MEDIUMINT UNSIGNED NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              UNIQUE KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `users_2fa` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `mechanism` TINYINT UNSIGNED NOT NULL,
              `seed` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              `created_at` INT UNSIGNED NOT NULL,
              `expires_at` INT UNSIGNED DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `user_id_mechanism` (`user_id`,`mechanism`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `users_audit_log` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED DEFAULT NULL,
              `event_at` INT UNSIGNED NOT NULL,
              `event_type` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
              `admin_id` INT UNSIGNED DEFAULT NULL,
              `ip_address` VARCHAR(49) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
              `user_agent` TEXT COLLATE utf8mb4_unicode_ci,
              `details_json` TEXT COLLATE utf8mb4_unicode_ci,
              PRIMARY KEY (`id`),
              KEY `event_at` (`event_at`),
              KEY `user_id_event_at` (`user_id`,`event_at`),
              KEY `user_id_event_type_event_at` (`user_id`,`event_type`,`event_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `users_confirmations` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `email` VARCHAR(249) COLLATE utf8mb4_unicode_ci NOT NULL,
              `selector` VARCHAR(16) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `token` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `expires` INT UNSIGNED NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `selector` (`selector`),
              KEY `email_expires` (`email`,`expires`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `users_otps` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `mechanism` TINYINT UNSIGNED NOT NULL,
              `single_factor` TINYINT UNSIGNED NOT NULL DEFAULT '0',
              `selector` VARCHAR(24) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `token` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `expires_at` INT UNSIGNED DEFAULT NULL,
              PRIMARY KEY (`id`),
              KEY `user_id_mechanism` (`user_id`,`mechanism`),
              KEY `selector_user_id` (`selector`,`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `users_remembered` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user` INT UNSIGNED NOT NULL,
              `selector` VARCHAR(24) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `token` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `expires` INT UNSIGNED NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `selector` (`selector`),
              KEY `user` (`user`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `users_resets` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user` INT UNSIGNED NOT NULL,
              `selector` VARCHAR(20) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `token` VARCHAR(255) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `expires` INT UNSIGNED NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `selector` (`selector`),
              KEY `user_expires` (`user`,`expires`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->execute(<<<'SQL'
            CREATE TABLE `users_throttling` (
              `bucket` VARCHAR(44) CHARACTER SET latin1 COLLATE latin1_general_cs NOT NULL,
              `tokens` FLOAT NOT NULL,
              `replenished_at` INT UNSIGNED NOT NULL,
              `expires_at` INT UNSIGNED NOT NULL,
              PRIMARY KEY (`bucket`),
              KEY `expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        foreach ([
            'users_throttling',
            'users_resets',
            'users_remembered',
            'users_otps',
            'users_confirmations',
            'users_audit_log',
            'users_2fa',
            'users',
        ] as $table) {
            $this->execute('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
}
