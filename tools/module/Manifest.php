<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * modules.json at the app's root (docs/modules.md, step 4): what is
 * installed. Written only by `record`, after a green suite (22).
 */
final class Manifest
{
    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string,array<string,mixed>> name => entry */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $json = json_decode((string) file_get_contents($this->file), true);

        if (!is_array($json) || !is_array($json['modules'] ?? null)) {
            throw new Refusal(basename($this->file) . ' is not a module record this command can read; fix it by hand.');
        }

        ksort($json['modules']);

        return $json['modules'];
    }

    /** @return array<string,mixed>|null */
    public function get(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** @param array<string,mixed> $entry */
    public function put(string $name, array $entry): void
    {
        $modules = $this->all();
        $modules[$name] = $entry;
        ksort($modules);
        file_put_contents($this->file, json_encode(['modules' => $modules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
