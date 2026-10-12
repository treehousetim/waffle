<?php

declare(strict_types=1);

namespace Installer;

use Composer\Script\Event;

/**
 * Runs once, at the end of `composer create-project treehousetim/waffle <dir>`.
 *
 * Asks for the slug, display name and hostname, suggesting each from the
 * directory name, and hands them to tools/rename-project.sh, which validates
 * them and does the work. The script is the authority on what is valid; a
 * refused answer is shown and asked again.
 *
 * Outside src/app on purpose: Composer\Script\Event exists only while Composer
 * is running, and none of this is the application. docs/create-project.md has
 * the failure list; the numbers below cite it.
 */
final class CreateProject
{
    private const int ATTEMPTS = 3;

    public static function postCreate(Event $event): void
    {
        $io = $event->getIO();
        $root = dirname(__DIR__, 2);

        // (11) No terminal, no guessing: leave the placeholders to be chosen.
        if (!self::shouldAsk($io->isInteractive())) {
            $io->write('<comment>Not renaming: no terminal to ask on. Run ./tools/rename-project.sh <slug> "<Name>" <hostname> yourself.</comment>');

            return;
        }

        $io->write('<info>Name your project. Press enter to take the suggestion.</info>');

        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            $slug = trim((string) $io->ask(sprintf('Slug [%s]: ', $suggested = self::defaultSlug(basename($root))), $suggested));
            $name = trim((string) $io->ask(sprintf('Display name [%s]: ', $suggestedName = self::defaultName($slug)), $suggestedName));
            $host = trim((string) $io->ask(sprintf('Production hostname [%s]: ', $suggestedHost = self::defaultHost($slug)), $suggestedHost));

            $process = proc_open(['bash', $root . '/tools/rename-project.sh', $slug, $name, $host], [STDIN, STDOUT, STDERR], $pipes, $root);
            $code = is_resource($process) ? proc_close($process) : 1;

            if ($code === 0) {
                return;
            }

            // 2 is a refused answer; anything else will not improve by asking.
            if ($code !== 2) {
                break;
            }
        }

        $io->writeError('<error>Not renamed. Run ./tools/rename-project.sh <slug> "<Name>" <hostname> when ready.</error>');
    }

    public static function shouldAsk(bool $interactive): bool
    {
        return $interactive;
    }

    /**
     * (12) A valid slug from any directory name: lowercase, anything else an
     * underscore, a letter first, 2 to 31 characters.
     */
    public static function defaultSlug(string $directory): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($directory)), '_');

        if ($slug === '') {
            return 'app';
        }

        if (!ctype_alpha($slug[0])) {
            $slug = 'app_' . $slug;
        }

        if (strlen($slug) < 2) {
            $slug .= '_app';
        }

        return rtrim(substr($slug, 0, 31), '_');
    }

    public static function defaultName(string $slug): string
    {
        return ucwords(str_replace('_', ' ', $slug));
    }

    /**
     * Underscores are not allowed in hostnames, so they become dashes.
     */
    public static function defaultHost(string $slug): string
    {
        return str_replace('_', '-', $slug) . '.example.com';
    }
}
