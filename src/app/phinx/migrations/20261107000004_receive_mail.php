<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Mail sent to the domain (docs/mail.md, "Mail sent to the domain"): webhook
 * deliveries recorded by their own id, the Inbox's Email form, the queue's job
 * that fetches and files a message, and a submission that came from no site.
 * Only adds: making the site optional changes nothing a release before this
 * one writes (AGENTS.md §6).
 */
final class ReceiveMail extends AbstractMigration
{
    public function up(): void
    {
        $this->table('types')->insert([
            ['types_group' => 'form', 'types_name' => 'email', 'types_label' => 'Email', 'types_sort' => 20],
            ['types_group' => 'job', 'types_name' => 'inbound-mail', 'types_label' => 'File an email sent to the domain', 'types_sort' => 50],
        ])->saveData();

        $this->execute('ALTER TABLE `form_submissions` MODIFY `form_submissions_site_id` INT UNSIGNED DEFAULT NULL');

        $this->execute(<<<'SQL'
            CREATE TABLE `webhook_deliveries` (
              `webhook_deliveries_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `webhook_deliveries_provider` VARCHAR(40) NOT NULL,
              `webhook_deliveries_delivery_id` VARCHAR(100) NOT NULL,
              `webhook_deliveries_kind` VARCHAR(60) NOT NULL,
              `webhook_deliveries_payload_hash` CHAR(64) NOT NULL,
              `webhook_deliveries_received_at` DATETIME NOT NULL,
              PRIMARY KEY (`webhook_deliveries_id`),
              UNIQUE KEY `webhook_deliveries_provider_delivery` (`webhook_deliveries_provider`, `webhook_deliveries_delivery_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `webhook_deliveries`');
        $this->execute("DELETE FROM `form_submissions` WHERE `form_submissions_site_id` IS NULL");
        $this->execute('ALTER TABLE `form_submissions` MODIFY `form_submissions_site_id` INT UNSIGNED NOT NULL');
        $this->execute("DELETE FROM `types` WHERE (`types_group` = 'form' AND `types_name` = 'email') OR (`types_group` = 'job' AND `types_name` = 'inbound-mail')");
    }
}
