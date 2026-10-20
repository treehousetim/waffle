<?php

declare(strict_types=1);

/*
 * CI (.github/workflows/ci.yml): the gate on every push to main and every
 * pull request. A pull request's code is a stranger's, so the job that runs
 * it must hold nothing worth taking.
 */

function ciWorkflow(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
}

it('runs the whole gate at 100% on pushes to main and on pull requests', function (): void {
    expect(ciWorkflow())
        ->toMatch('/^on:\n  push:\n    branches: \[main\]\n  pull_request:\n/m')
        ->toMatch('/^        run: \.\/tools\/test\.sh --coverage$/m');
});

it('reads the repository and nothing else: no secret, no environment, no write token', function (): void {
    expect(ciWorkflow())
        ->toMatch('/^permissions:\n  contents: read\n/m')
        ->not->toContain('secrets.')
        ->not->toMatch('/^\s+environment:/m')
        ->not->toContain('pull_request_target')
        ->toContain('persist-credentials: false');
});

it('pins every action to a commit', function (): void {
    preg_match_all('/uses: (\S+)/', ciWorkflow(), $uses);

    expect($uses[1])->not->toBeEmpty()
        ->each->toMatch('/@[0-9a-f]{40}$/');
});
