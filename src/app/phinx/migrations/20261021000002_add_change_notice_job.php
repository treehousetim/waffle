<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The queue's job type for change notices (docs/content.md): every job type
 * is a `types` row in the group `job` (docs/queue.md, 11.).
 */
final class AddChangeNoticeJob extends AbstractMigration
{
    public function up(): void
    {
        $this->table('types')->insert([
            ['types_group' => 'job', 'types_name' => 'site-change-notice', 'types_label' => 'Tell the site what changed', 'types_sort' => 10],
        ])->saveData();
    }

    public function down(): void
    {
        $this->execute("DELETE FROM `types` WHERE `types_group` = 'job' AND `types_name` = 'site-change-notice'");
    }
}
