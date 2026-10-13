<?php

declare(strict_types=1);

namespace ModuleTool;

use DateTimeImmutable;
use DateTimeZone;
use ModuleTool\Clients\HttpsTransport;

/**
 * The PHP half of tools/module.sh: one subcommand per call, a Refusal printed
 * as an error and exit 1, a usage mistake exit 2.
 */
final class Cli
{
    /**
     * @param list<string> $argv
     * @param resource     $out
     * @param resource     $err
     */
    public static function run(Command $command, array $argv, mixed $out, mixed $err): int
    {
        $args = array_slice($argv, 1);
        $verb = (string) array_shift($args);

        try {
            return match (true) {
                $verb === 'search' => $command->search($args),
                $verb === 'info' && count($args) === 1 => $command->info($args[0]),
                $verb === 'prepare' && count($args) === 2 => $command->prepare($args[0], $args[1], false),
                $verb === 'prepare-upgrade' && count($args) === 2 => $command->prepare($args[0], $args[1], true),
                $verb === 'stage' && count($args) === 1 => $command->stage($args[0]),
                $verb === 'prompt' && count($args) === 1 => $command->prompt($args[0]),
                $verb === 'record-check' && count($args) === 1 => $command->recordCheck($args[0]),
                $verb === 'record-write' && count($args) === 1 => $command->recordWrite($args[0]),
                $verb === 'outdated' && $args === [] => $command->outdated(),
                $verb === 'verify' && $args === [] => $command->verify(),
                $verb === 'doctor' && $args === [] => $command->doctor(),
                default => self::usage($err),
            };
        } catch (Refusal $refusal) {
            fwrite($err, 'error: ' . $refusal->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * The command as tools/module.sh runs it, in the app's root.
     *
     * @param resource $out
     */
    public static function command(string $root, mixed $out): Command
    {
        $git = new Git();

        return new Command($root, $git, new HttpsTransport(), new Iron($root . '/.waffle/iron', $git), new DateTimeImmutable('now', new DateTimeZone('UTC')), $out);
    }

    /** @param resource $err */
    private static function usage(mixed $err): int
    {
        fwrite($err, "usage: tools/module/run.php search [words] | info <name> | prepare <name>[@X.Y.Z] <scratch> | prepare-upgrade <name> <scratch> | stage <scratch> | prompt <name> | record-check <name> | record-write <name> | outdated | verify | doctor\n");

        return 2;
    }
}
