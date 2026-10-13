<?php

declare(strict_types=1);

/*
 * tools/module.sh, the bash half of the module command (docs/module-command.md):
 * it dispatches, asks, starts the agent and runs the suite. Run against a
 * scratch app with Docker, the agent and the test runner replaced by shims that
 * log what they were asked to do; the PHP half has its own tests in
 * tests/Unit/ModuleTool/. The numbers cite the document's failure list.
 */

function moduleScriptFixture(): string
{
    $root = dirname(__DIR__, 2);
    $dir = sys_get_temp_dir() . '/waffle-module-sh-' . bin2hex(random_bytes(6));
    mkdir($dir . '/tools', 0o777, true);
    mkdir($dir . '/bin');
    copy($root . '/tools/module.sh', $dir . '/tools/module.sh');
    chmod($dir . '/tools/module.sh', 0o755);

    foreach (['VERSION' => "3\n", 'AGENTS.md' => "# AGENTS.md\n", 'composer.json' => "{}\n"] as $file => $contents) {
        file_put_contents($dir . '/' . $file, $contents);
    }

    // Docker: `info` answers FAKE_DOCKER_UP; the PHP half answers per verb.
    file_put_contents($dir . '/bin/docker', <<<'SH'
        #!/usr/bin/env bash
        here="$(cd "$(dirname "$0")/.." && pwd)"
        printf 'docker %s\n' "$*" >> "$here/calls.log"
        [ "$1" = "info" ] && exit "${FAKE_DOCKER_UP:-0}"
        verb=""; seen=0
        for arg in "$@"; do
            if [ "$seen" -eq 1 ]; then verb="$arg"; break; fi
            [ "$arg" = "tools/module/run.php" ] && seen=1
        done
        case "$verb" in
            prepare|prepare-upgrade)
                # The PHP half creates the scratch directory it is given.
                mkdir -p "$here/${@: -1}"
                echo "SHOWN: what the module adds"
                exit "${FAKE_PREPARE_EXIT:-0}" ;;
            prompt) echo "THE FIXED PROMPT" ;;
            verify) exit "${FAKE_VERIFY_EXIT:-0}" ;;
            record-check) exit "${FAKE_CHECK_EXIT:-0}" ;;
        esac
        exit 0
        SH);
    file_put_contents($dir . '/tools/test.sh', <<<'SH'
        #!/usr/bin/env bash
        here="$(cd "$(dirname "$0")/.." && pwd)"
        printf 'test.sh %s\n' "$*" >> "$here/calls.log"
        exit "${FAKE_TEST_EXIT:-0}"
        SH);
    file_put_contents($dir . '/bin/agent', <<<'SH'
        #!/usr/bin/env bash
        here="$(cd "$(dirname "$0")/.." && pwd)"
        printf 'agent %s\n' "$*" >> "$here/calls.log"
        SH);

    foreach (['docker', 'agent'] as $shim) {
        chmod($dir . '/bin/' . $shim, 0o755);
    }

    chmod($dir . '/tools/test.sh', 0o755);

    return $dir;
}

/**
 * Run the script; with `$answers`, inside a terminal (util-linux `script`)
 * that is typed those lines.
 *
 * @param list<string>          $args
 * @param array<string,string>  $env
 *
 * @return array{code:int,out:string,err:string,calls:list<string>}
 */
function runModuleScript(string $dir, array $args, array $env = [], ?string $answers = null): array
{
    $command = array_merge(['bash', $dir . '/tools/module.sh'], $args);

    if ($answers !== null) {
        $command = ['script', '-qec', implode(' ', array_map('escapeshellarg', $command)), '/dev/null'];
    }

    $process = proc_open(
        $command,
        [0 => $answers === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $dir,
        array_merge(['PATH' => $dir . '/bin:' . getenv('PATH'), 'HOME' => '/home/someone', 'MODULE_CLAUDE' => 'agent'], $env),
    );

    if ($answers !== null) {
        fwrite($pipes[0], $answers);
        fclose($pipes[0]);
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $log = $dir . '/calls.log';

    return [
        'code' => $code,
        'out' => $out,
        'err' => $err,
        'calls' => is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [],
    ];
}

/** The PHP verbs the script ran, in order. @param list<string> $calls */
function phpVerbs(array $calls): array
{
    $verbs = [];

    foreach ($calls as $call) {
        if (preg_match('#tools/module/run\.php (\S+)#', $call, $match)) {
            $verbs[] = $match[1];
        } elseif (str_starts_with($call, 'test.sh') || str_starts_with($call, 'agent')) {
            $verbs[] = strtok($call, ' ');
        }
    }

    return $verbs;
}

beforeEach(function (): void {
    $this->dir = moduleScriptFixture();
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

it('prints its usage with no arguments, and refuses one it does not know', function (): void {
    expect(runModuleScript($this->dir, [])['out'])->toContain('./tools/module.sh use <name>[@<version>]')
        ->and(runModuleScript($this->dir, ['uninstall', 'notes'])['code'])->toBe(2);
});

it('runs the checks in the php container, as the invoking user, with none of the home directory (20)', function (): void {
    $run = runModuleScript($this->dir, ['search', 'quote', 'form']);

    expect($run['code'])->toBe(0, $run['err'])
        ->and(end($run['calls']))->toBe('docker compose run --rm --no-deps -T --user ' . posix_getuid() . ':' . posix_getgid() . ' -e HOME=/tmp php php tools/module/run.php search quote form')
        ->and(implode("\n", $run['calls']))->not->toContain('/home/someone');
});

it('passes info, outdated and verify through, with their exit codes', function (): void {
    expect(phpVerbs(runModuleScript($this->dir, ['info', 'notes'])['calls']))->toBe(['info'])
        ->and(runModuleScript($this->dir, ['outdated'])['code'])->toBe(0)
        ->and(runModuleScript($this->dir, ['verify'], ['FAKE_VERIFY_EXIT' => '1'])['code'])->toBe(1)
        ->and(runModuleScript($this->dir, ['verify', 'extra'])['code'])->toBe(2)
        ->and(runModuleScript($this->dir, ['info'])['code'])->toBe(2);
});

it('refuses a name that is not one before it reaches any command (20)', function (array $args): void {
    $run = runModuleScript($this->dir, $args);

    expect($run['code'])->toBe(2)
        ->and($run['calls'])->toBe([]);
})->with([
    'use, a shell command' => [['use', 'notes;rm -rf ~']],
    'use, a substitution' => [['use', '$(touch pwned)']],
    'use, a short version' => [['use', 'notes@1.0']],
    'install, an option' => [['install', '--yes']],
    'info, a path' => [['info', '../notes']],
    'upgrade, a version' => [['upgrade', 'notes@1.0.0']],
    'record, upper case' => [['record', 'Notes']],
]);

it('holds no eval, and never runs what it is given (20)', function (): void {
    $code = (string) preg_replace('/^\s*#.*$/m', '', (string) file_get_contents(dirname(__DIR__, 2) . '/tools/module.sh'));

    expect($code)->not->toMatch('/\beval\b/');
});

it('stops before anything when this is not a Waffle app, or Docker is not running (28)', function (string $missing, array $env, string $message): void {
    if ($missing !== '') {
        unlink($this->dir . '/' . $missing);
    }

    $run = runModuleScript($this->dir, ['search'], $env);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain($message)
        ->and(phpVerbs($run['calls']))->toBe([]);
})->with([
    'no AGENTS.md' => ['AGENTS.md', [], 'this is not a Waffle app'],
    'no VERSION' => ['VERSION', [], 'this is not a Waffle app'],
    'no composer.json' => ['composer.json', [], 'this is not a Waffle app'],
    'Docker not running' => ['', ['FAKE_DOCKER_UP' => '1'], 'Docker is needed and is not running'],
    'no Docker at all' => ['', ['MODULE_DOCKER' => 'no-such-docker'], 'Docker is needed'],
]);

it('says what doctor found, and checks the container only when the rest is well (28)', function (): void {
    $run = runModuleScript($this->dir, ['doctor']);

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('ok    a Waffle app')
        ->and($run['out'])->toContain('ok    Docker is running')
        ->and($run['out'])->toContain('ok    agent is installed')
        ->and(phpVerbs($run['calls']))->toBe(['doctor']);

    unlink($this->dir . '/AGENTS.md');
    unlink($this->dir . '/calls.log');
    $run = runModuleScript($this->dir, ['doctor'], ['FAKE_DOCKER_UP' => '1', 'MODULE_CLAUDE' => 'no-such-agent']);

    expect($run['code'])->toBe(1)
        ->and($run['out'])->toContain('FAIL  not a Waffle app')
        ->and($run['out'])->toContain('FAIL  Docker is not installed or not running')
        ->and($run['out'])->toContain('note  no-such-agent is not installed')
        ->and(phpVerbs($run['calls']))->toBe([]);
});

it('refuses a native Windows shell (29)', function (): void {
    file_put_contents($this->dir . '/bin/uname', "#!/bin/sh\necho MINGW64_NT-10.0\n");
    chmod($this->dir . '/bin/uname', 0o755);

    $run = runModuleScript($this->dir, ['search']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('WSL2');
});

it('stages nothing without a terminal to ask on, and has no flag to skip the question (19)', function (): void {
    $run = runModuleScript($this->dir, ['use', 'notes']);

    expect($run['code'])->toBe(1)
        ->and($run['err'])->toContain('no terminal to ask on, so nothing was staged')
        ->and(phpVerbs($run['calls']))->toBe(['prepare'])
        ->and(runModuleScript($this->dir, ['use', 'notes', '--yes'])['code'])->toBe(2);
});

it('removes its scratch directory on every way out (30)', function (array $env, ?string $answers): void {
    runModuleScript($this->dir, ['use', 'notes'], $env, $answers);

    expect(glob($this->dir . '/.waffle/scratch/*') ?: [])->toBe([]);
})->with([
    'a refusal' => [['FAKE_PREPARE_EXIT' => '1'], null],
    'no terminal' => [[], null],
    'a no' => [[], "n\n"],
    'a finished install' => [[], "y\nn\n"],
]);

it('stops on a refusal from the checks (15)', function (): void {
    $run = runModuleScript($this->dir, ['use', 'notes'], ['FAKE_PREPARE_EXIT' => '1'], "y\n");

    expect($run['code'])->not->toBe(0)
        ->and(phpVerbs($run['calls']))->toBe(['prepare']);
});

it('stages nothing when the owner says no (19)', function (): void {
    $run = runModuleScript($this->dir, ['use', 'notes'], [], "n\n");

    expect($run['code'])->toBe(1)
        ->and($run['out'])->toContain('SHOWN: what the module adds')
        ->and(phpVerbs($run['calls']))->toBe(['prepare']);
});

it('shows, stages, starts the agent with the fixed prompt, then records on a green suite', function (string $verb, string $prepare): void {
    $run = runModuleScript($this->dir, [$verb, 'notes'], [], "y\ny\n");

    expect($run['code'])->toBe(0, $run['out'])
        ->and(phpVerbs($run['calls']))->toBe([$prepare, 'stage', 'prompt', 'agent', 'record-check', 'test.sh', 'record-write'])
        ->and($run['calls'])->toContain('agent THE FIXED PROMPT')
        ->and($run['calls'])->toContain('test.sh --coverage');
})->with([
    'use' => ['use', 'prepare'],
    'install, its alias' => ['install', 'prepare'],
    'upgrade' => ['upgrade', 'prepare-upgrade'],
]);

it('takes a pinned version', function (): void {
    $run = runModuleScript($this->dir, ['use', 'notes@1.2.0'], [], "n\n");

    expect(implode("\n", $run['calls']))->toContain('tools/module/run.php prepare notes@1.2.0 .waffle/scratch/run-');
});

it('leaves recording for later when the owner says so', function (): void {
    $run = runModuleScript($this->dir, ['use', 'notes'], [], "y\nn\n");

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('When it is done: ./tools/module.sh record notes')
        ->and(phpVerbs($run['calls']))->toBe(['prepare', 'stage', 'prompt', 'agent']);
});

it('prints the prompt for another agent when claude is not installed (21)', function (): void {
    $run = runModuleScript($this->dir, ['use', 'notes'], ['MODULE_CLAUDE' => 'no-such-agent'], "y\n");

    expect($run['code'])->toBe(0)
        ->and($run['out'])->toContain('no-such-agent is not installed. Give your agent this prompt')
        ->and($run['out'])->toContain('THE FIXED PROMPT')
        ->and($run['out'])->toContain('Then: ./tools/module.sh record notes')
        ->and(phpVerbs($run['calls']))->toBe(['prepare', 'stage', 'prompt']);
});

it('records only after it ran the whole suite itself and it was green (22)', function (): void {
    $green = runModuleScript($this->dir, ['record', 'notes']);
    $red = runModuleScript($this->dir, ['record', 'notes'], ['FAKE_TEST_EXIT' => '1']);

    expect($green['code'])->toBe(0)
        ->and(phpVerbs($green['calls']))->toBe(['record-check', 'test.sh', 'record-write'])
        ->and($red['code'])->toBe(1)
        ->and($red['err'])->toContain('the suite is not green, so notes is not recorded');

    $calls = array_slice($red['calls'], count($green['calls']));
    expect(phpVerbs($calls))->toBe(['record-check', 'test.sh']);
});

it('does not run the suite when the staged copy or shipped tests fail their check (23, 24)', function (): void {
    $run = runModuleScript($this->dir, ['record', 'notes'], ['FAKE_CHECK_EXIT' => '1']);

    expect($run['code'])->toBe(1)
        ->and(phpVerbs($run['calls']))->toBe(['record-check']);
});

it('is the entry point iron finds: executable, and the subcommand first', function (): void {
    expect(is_executable(dirname(__DIR__, 2) . '/tools/module.sh'))->toBeTrue()
        ->and((string) file_get_contents(dirname(__DIR__, 2) . '/tools/module.sh'))->toContain('verb="$1"');
});
