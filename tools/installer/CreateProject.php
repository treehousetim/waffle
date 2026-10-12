<?php

declare(strict_types=1);

namespace Installer;

use Composer\Script\Event;

/**
 * Runs once, at the end of `composer create-project treehousetim/waffle <dir>`.
 *
 * Hands over to tools/setup.sh, the same guided setup someone who downloaded
 * or cloned the project runs by hand, so there is one flow to keep right: it
 * checks the machine, asks for the names (suggesting each), renames, refreshes
 * the lock files and offers the first commit.
 *
 * Outside src/app on purpose: Composer\Script\Event exists only while Composer
 * is running, and none of this is the application. docs/create-project.md has
 * the failure list; the numbers below cite it.
 */
final class CreateProject
{
    public static function postCreate(Event $event): void
    {
        $io = $event->getIO();
        $root = dirname(__DIR__, 2);

        // (11) No terminal, no guessing: leave the placeholders to be chosen.
        if (!self::shouldAsk($io->isInteractive())) {
            $io->write('<comment>Not renaming: no terminal to ask on. Run ./tools/setup.sh in the new directory.</comment>');

            return;
        }

        $process = proc_open(['bash', $root . '/tools/setup.sh'], [STDIN, STDOUT, STDERR], $pipes, $root);
        $code = is_resource($process) ? proc_close($process) : 1;

        if ($code !== 0) {
            $io->writeError('<error>Setup did not finish. Run ./tools/setup.sh in the new directory to pick up where it stopped.</error>');
        }
    }

    public static function shouldAsk(bool $interactive): bool
    {
        return $interactive;
    }
}
