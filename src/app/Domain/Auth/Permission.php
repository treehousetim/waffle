<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Everything a level may or may not be allowed to do, one case each.
 *
 * Every route names one (`can:<value>`), the frontend hides every page, link
 * and button whose permission the person lacks (`/api/auth/me` carries the
 * list), and Permissions::GRID decides who holds which. A new capability is a
 * new case here, a row in the grid, and a line in
 * tests/Support/access-routes.php — AccessGridTest fails until all three agree.
 *
 * The values are kebab-case because they travel: in the API, in the
 * frontend's route meta, and in docs/access-levels.md.
 */
enum Permission: string
{
    case Home = 'home';
    case SettingsView = 'settings-view';
    case SettingsEdit = 'settings-edit';
    case QueueManage = 'queue-manage';
    case ContentEdit = 'content-edit';
    case ContentPublish = 'content-publish';
    case InvitationsManage = 'invitations-manage';
}
