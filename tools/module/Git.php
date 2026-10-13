<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * git, with arguments as a list (never a shell string) and an environment of
 * its own: no system or user configuration, no prompts, HTTPS only (1, 20).
 */
final class Git
{
    /** @param array<string,string> $env added to, or replacing, the defaults */
    public function __construct(private readonly array $env = [])
    {
    }

    /**
     * @param list<string> $args
     *
     * @return array{code:int,out:string,err:string}
     */
    public function run(array $args, ?string $cwd = null): array
    {
        $env = array_merge([
            'PATH' => (string) getenv('PATH'),
            'HOME' => sys_get_temp_dir(),
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ALLOW_PROTOCOL' => 'https',
            'LC_ALL' => 'C',
        ], $this->env);

        $process = proc_open(
            array_merge(['git', '-c', 'safe.directory=*'], $args),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $env,
        );
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /** @param list<string> $args */
    public function must(array $args, string $cwd, string $why): string
    {
        $result = $this->run($args, $cwd);

        if ($result['code'] !== 0) {
            throw new Refusal($why . ' ' . Terminal::safe(trim($result['err'])));
        }

        return $result['out'];
    }
}
