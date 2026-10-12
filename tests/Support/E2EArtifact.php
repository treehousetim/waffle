<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * The record an end-to-end test leaves behind: a verifiable, repeatable
 * artifact under coverage/e2e/<name>.json.
 *
 * Two rules make it repeatable:
 *
 *  - **Facts are named by role, never by database id or timestamp.** "the
 *    owner", not "user 4183". Ids and clocks differ between runs; roles do not.
 *  - **The digest covers the facts and the checks, nothing else.** So two runs
 *    of an unchanged codebase write the same SHA-256, and a run whose digest
 *    changed is a run whose observed behaviour changed.
 *
 * Usage, at the end of an E2E test:
 *
 *     $artifact = new E2EArtifact('sign-in');
 *     $artifact->fact('owner sees hello', $message);
 *     $artifact->check('logout ends the session', $status === 401);
 *     $path = $artifact->write();   // throws if any check failed
 */
final class E2EArtifact
{
    /** @var array<string,mixed> */
    private array $facts = [];

    /** @var array<string,bool> */
    private array $checks = [];

    public function __construct(private readonly string $name)
    {
    }

    public function fact(string $role, mixed $value): self
    {
        $this->facts[$role] = $value;

        return $this;
    }

    public function check(string $description, bool $passed): self
    {
        $this->checks[$description] = $passed;

        return $this;
    }

    /** The SHA-256 of the facts and checks, in a stable encoding. */
    public function digest(): string
    {
        return hash('sha256', json_encode(
            ['facts' => $this->facts, 'checks' => $this->checks],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Writes the artifact and returns its path.
     *
     * Throws when a check failed, after writing: the file is the evidence of
     * what went wrong, and the exception fails the test.
     */
    public function write(): string
    {
        $directory = dirname(__DIR__, 2) . '/coverage/e2e';

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory . '/' . $this->name . '.json';
        $failed = array_keys(array_filter($this->checks, static fn (bool $passed): bool => !$passed));

        file_put_contents($path, json_encode([
            'name' => $this->name,
            'facts' => $this->facts,
            'checks' => $this->checks,
            'passed' => $failed === [],
            'sha256' => $this->digest(),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        if ($failed !== []) {
            throw new RuntimeException("E2E {$this->name} failed: " . implode('; ', $failed) . " (see {$path})");
        }

        return $path;
    }
}
