<?php

declare(strict_types=1);

use ModuleTool\Cli;
use Tests\Support\ModuleFixtures;

/* The PHP half's command line, as tools/module.sh calls it. */

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
    $this->app = ModuleFixtures::app($this->base);
    $commit = ModuleFixtures::source($this->base);
    ModuleFixtures::catalogue($this->base, ['1.0.0' => ModuleFixtures::entry($commit, ModuleFixtures::hash($this->base, $commit))]);
    $this->out = fopen('php://memory', 'w+');
    $this->err = fopen('php://memory', 'w+');
    $this->run = function (string ...$args): int {
        return Cli::run(ModuleFixtures::command($this->base, $this->app, $this->out), array_merge(['cli.php'], $args), $this->out, $this->err);
    };
    $this->read = function ($stream): string {
        rewind($stream);

        return (string) stream_get_contents($stream);
    };
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

it('runs each subcommand', function (): void {
    expect(($this->run)('search', 'fixture'))->toBe(0)
        ->and(($this->run)('info', 'fixture-notes'))->toBe(0)
        ->and(($this->run)('prepare', 'fixture-notes', '.waffle/scratch/run-a'))->toBe(0)
        ->and(($this->run)('stage', '.waffle/scratch/run-a'))->toBe(0)
        ->and(($this->run)('prompt', 'fixture-notes'))->toBe(0)
        ->and(($this->run)('record-check', 'fixture-notes'))->toBe(0)
        ->and(($this->run)('record-write', 'fixture-notes'))->toBe(0)
        ->and(($this->run)('outdated'))->toBe(0)
        ->and(($this->run)('verify'))->toBe(0)
        ->and(($this->run)('doctor'))->toBe(0)
        ->and(($this->run)('prepare-upgrade', 'fixture-notes', '.waffle/scratch/run-b'))->toBe(1)
        ->and(($this->read)($this->err))->toContain('error: no version of fixture-notes can be installed here');
});

it('prints a refusal as an error and exits 1', function (): void {
    expect(($this->run)('info', 'Not_A_Name'))->toBe(1)
        ->and(($this->read)($this->err))->toBe("error: 'Not_A_Name' is not a module name, or name@X.Y.Z.\n");
});

it('exits 2 with the usage on anything it does not know', function (array $args): void {
    expect(($this->run)(...$args))->toBe(2)
        ->and(($this->read)($this->err))->toStartWith('usage: tools/module/run.php');
})->with([
    'nothing' => [[]],
    'an unknown subcommand' => [['uninstall', 'fixture-notes']],
    'a missing argument' => [['info']],
    'an extra argument' => [['verify', 'now']],
]);

it('builds the real command for the app it is run in', function (): void {
    $out = fopen('php://memory', 'w+');

    expect(Cli::run(Cli::command($this->app, $out), ['cli.php', 'doctor'], $out, $this->err))->toBe(0)
        ->and(($this->read)($out))->toContain('ok    git in the php container');
});
