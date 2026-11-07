<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The queue's job type for emailing an invitation (docs/invitations.md,
 * docs/mail.md): every job type is a `types` row in the group `job`
 * (docs/queue.md, 11.).
 */
final class AddInvitationMailJob extends AbstractMigration
{
    public function up(): void
    {
        $this->table('types')->insert([
            ['types_group' => 'job', 'types_name' => 'invitation-mail', 'types_label' => 'Email an invitation', 'types_sort' => 30],
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'job' AND `types_name` = 'invitation-mail'");
    }
}
