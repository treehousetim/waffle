<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Invitations (docs/invitations.md): how people join a closed app. The token
 * itself is never stored, only its SHA-256 (3.).
 */
final class CreateInvitationsTable extends AbstractMigration
{
    public function up(): void
    {
        $sort = 0;
        $rows = [];

        // App\Domain\Invitations\InvitationStatus
        foreach (['pending' => 'Waiting to be accepted', 'accepted' => 'Accepted', 'revoked' => 'Revoked'] as $name => $label) {
            $sort += 10;
            $rows[] = ['types_group' => 'invitation_status', 'types_name' => $name, 'types_label' => $label, 'types_sort' => $sort];
        }

        $this->table('types')->insert($rows)->saveData();

        $this->execute(<<<'SQL'
            CREATE TABLE `invitations` (
              `invitations_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `invitations_email` VARCHAR(249) NOT NULL,
              `invitations_role` VARCHAR(20) NOT NULL,
              `invitations_token_hash` CHAR(64) NOT NULL,
              `invitations_status_id` INT UNSIGNED NOT NULL,
              `invitations_created_by` INT UNSIGNED NOT NULL,
              `invitations_created_at` DATETIME NOT NULL,
              `invitations_expires_at` DATETIME NOT NULL,
              `invitations_decided_at` DATETIME DEFAULT NULL,
              `invitations_user_id` INT UNSIGNED DEFAULT NULL,
              PRIMARY KEY (`invitations_id`),
              UNIQUE KEY `invitations_token_hash` (`invitations_token_hash`),
              KEY `invitations_email_status` (`invitations_email`, `invitations_status_id`),
              CONSTRAINT `invitations_status_fk` FOREIGN KEY (`invitations_status_id`) REFERENCES `types` (`types_id`),
              CONSTRAINT `invitations_created_by_fk` FOREIGN KEY (`invitations_created_by`) REFERENCES `users` (`id`),
              CONSTRAINT `invitations_user_fk` FOREIGN KEY (`invitations_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `invitations`');
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'invitation_status'");
    }
}
