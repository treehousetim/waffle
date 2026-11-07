<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The queue's job type for an email that carries no secret (docs/mail.md):
 * every job type is a `types` row in the group `job` (docs/queue.md, 11.).
 */
final class AddSendMailJob extends AbstractMigration
{
    public function up(): void
    {
        $this->table('types')->insert([
            ['types_group' => 'job', 'types_name' => 'send-mail', 'types_label' => 'Send an email', 'types_sort' => 20],
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'job' AND `types_name` = 'send-mail'");
    }
}
