<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Invitations\InvitationMail;
use App\Domain\Ports\MailPort;
use App\Domain\Ports\QueuePort;
use RuntimeException;
use Tests\TestCase;

/**
 * An invitation's link as the invited person gets it (docs/invitations.md):
 * the invitation emails queued so far are run through the real handler, and
 * the link is read from the last email to that address. The test container's
 * queue and mailer record instead of sending.
 */
final class InvitationLinks
{
    /** @var array<int,true> jobs already run, by object id */
    private static array $ran = [];

    public static function sentTo(string $email): string
    {
        $container = TestCase::container();
        $handler = $container->get('job:invitation-mail');

        foreach ($container->get(QueuePort::class)->enqueued as $queued) {
            $job = $queued['job'];

            if ($job instanceof InvitationMail && !isset(self::$ran[spl_object_id($job)])) {
                self::$ran[spl_object_id($job)] = true;
                $handler->handle($job);
            }
        }

        foreach (array_reverse($container->get(MailPort::class)->sent) as $sent) {
            if ($sent['message']->to === [strtolower($email)] && preg_match('#/invite/([A-Za-z0-9_-]{43})#', $sent['message']->text, $match) === 1) {
                return $match[1];
            }
        }

        throw new RuntimeException("No invitation was emailed to {$email}.");
    }
}
