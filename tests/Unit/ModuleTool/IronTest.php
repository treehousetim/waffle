<?php

declare(strict_types=1);

use ModuleTool\Git;
use ModuleTool\Iron;
use ModuleTool\Refusal;
use Tests\Support\ModuleFixtures;

/* Step 1 of `use`: the catalogue's clone (docs/module-command.md, 1 and 2). */

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
    $this->sha = str_repeat('a', 64);
    $this->commit = str_repeat('b', 40);
    ModuleFixtures::catalogue($this->base, ['1.0.0' => ModuleFixtures::entry($this->commit, $this->sha)]);
    $this->clone = $this->base . '/app/.waffle/iron';
    $this->iron = new Iron($this->clone, ModuleFixtures::git($this->base), ModuleFixtures::IRON);
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

it('fetches only from Waffle Iron, over HTTPS (1)', function (): void {
    expect(Iron::URL)->toBe('https://github.com/treehousetim/waffle-iron')
        ->and((string) file_get_contents(dirname(__DIR__, 3) . '/tools/module/Cli.php'))->toContain("new Iron(\$root . '/.waffle/iron', \$git)");
});

it('clones the catalogue, then follows it forward', function (): void {
    expect($this->iron->update()->names())->toBe(['fixture-notes']);

    ModuleFixtures::catalogue($this->base, ['1.0.0' => ModuleFixtures::entry($this->commit, $this->sha), '1.1.0' => ModuleFixtures::entry($this->commit, $this->sha)]);

    expect($this->iron->update()->find('fixture-notes', '1.1.0'))->not->toBeNull();
});

it('reads index.json from the commit, so an edit on disk changes nothing', function (): void {
    $this->iron->update();
    file_put_contents($this->clone . '/index.json', '{"schema":1,"modules":{}}');

    expect($this->iron->update()->names())->toBe(['fixture-notes']);
});

it('refuses a clone that points somewhere else (1)', function (): void {
    $this->iron->update();
    ModuleFixtures::run($this->clone, 'remote', 'set-url', 'origin', 'https://github.example/someone/else');

    expect(fn () => $this->iron->update())->toThrow(Refusal::class, 'fetches from https://github.example/someone/else, not ' . ModuleFixtures::IRON);
});

it('refuses plain HTTP even if a clone were pointed at it (1)', function (): void {
    $iron = new Iron($this->base . '/other', new Git(), 'http://github.example/treehousetim/waffle-iron');

    expect(fn () => $iron->update())->toThrow(Refusal::class, 'could not fetch the catalogue');
});

it('refuses a rewritten history and keeps the old clone (2)', function (): void {
    $this->iron->update();
    $before = ModuleFixtures::run($this->clone, 'rev-parse', 'HEAD');
    $repo = $this->base . '/repos/treehousetim/waffle-iron';
    ModuleFixtures::run($repo, 'commit', '--quiet', '--amend', '-m', 'rewritten');

    expect(fn () => $this->iron->update())->toThrow(Refusal::class, 'history was rewritten')
        ->and(ModuleFixtures::run($this->clone, 'rev-parse', 'HEAD'))->toBe($before);
});

it('refuses a clone with commits of its own (2)', function (): void {
    $this->iron->update();
    ModuleFixtures::run($this->clone, 'commit', '--quiet', '--allow-empty', '-m', 'local');

    expect(fn () => $this->iron->update())->toThrow(Refusal::class, 'history was rewritten');
});

it('refuses something in the way of the clone, and a catalogue it cannot reach (1)', function (): void {
    mkdir($this->clone, 0o777, true);

    expect(fn () => $this->iron->update())->toThrow(Refusal::class, 'is in the way');

    rmdir($this->clone);
    $this->iron->update();
    ModuleFixtures::remove($this->base . '/repos/treehousetim');

    expect(fn () => $this->iron->update())->toThrow(Refusal::class, 'could not reach the catalogue');
});

it('refuses a catalogue with no index.json', function (): void {
    $repo = $this->base . '/repos/treehousetim/waffle-iron';
    ModuleFixtures::run($repo, 'rm', '--quiet', 'index.json');
    ModuleFixtures::run($repo, 'commit', '--quiet', '-m', 'empty');

    expect(fn () => $this->iron->update())->toThrow(Refusal::class, 'has no index.json');
});
