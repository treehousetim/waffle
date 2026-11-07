<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Password reset requests (docs/password-reset.md), and the queue's job type
 * that emails one (docs/queue.md, 11.). The link's token is never stored,
 * only its SHA-256 once the email has gone (3., 9.).
 */
final class CreatePasswordResetsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('types')->insert([
            ['types_group' => 'job', 'types_name' => 'password-reset-mail', 'types_label' => 'Email a password reset link', 'types_sort' => 40],
        ])->saveData();

        $this->execute(<<<'SQL'
            CREATE TABLE `password_resets` (
              `password_resets_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `password_resets_user_id` INT UNSIGNED NOT NULL,
              `password_resets_token_hash` CHAR(64) DEFAULT NULL,
              `password_resets_requested_at` DATETIME NOT NULL,
              `password_resets_expires_at` DATETIME NOT NULL,
              `password_resets_closed_at` DATETIME DEFAULT NULL,
              PRIMARY KEY (`password_resets_id`),
              UNIQUE KEY `password_resets_token_hash` (`password_resets_token_hash`),
              KEY `password_resets_user_requested` (`password_resets_user_id`, `password_resets_requested_at`),
              CONSTRAINT `password_resets_user_fk` FOREIGN KEY (`password_resets_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `password_resets`');
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'job' AND `types_name` = 'password-reset-mail'");
    }
}
