<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * One version of one module in Waffle Iron's index.json, checked field by
 * field before anything uses it (34).
 */
final class Entry
{
    public const FORMS = ['directory', 'zip', 'file'];

    /**
     * @param array<string,string> $source  repository, commit, path; or url
     * @param list<string>         $provenOn
     */
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $waffle,
        public readonly string $form,
        public readonly array $source,
        public readonly string $sha256,
        public readonly array $provenOn,
        public readonly ?string $reviewedBy,
        public readonly ?string $yanked,
    ) {
    }

    /** @param array<mixed> $fields */
    public static function from(string $name, string $version, array $fields): self
    {
        $where = "{$name} {$version}";
        $field = static fn (string $key): mixed => $fields[$key] ?? null;
        $text = static fn (mixed $value): bool => is_string($value) && $value !== '';

        if (!preg_match(Format::NAME, $name) || !preg_match(Format::VERSION, $version)) {
            throw new Refusal('the catalogue lists ' . Terminal::safe($where) . ', which is not a module name and X.Y.Z version (34).');
        }

        if (!$text($field('waffle'))) {
            throw new Refusal("the catalogue's entry for {$where} has no 'waffle' constraint (34).");
        }

        Constraint::parse($field('waffle'));

        if (!in_array($field('form'), self::FORMS, true)) {
            throw new Refusal("the catalogue's entry for {$where} has no form this command knows: directory, zip or file (34).");
        }

        $source = $field('source');

        if (!is_array($source)) {
            throw new Refusal("the catalogue's entry for {$where} has no source (34).");
        }

        if ($field('form') === 'directory') {
            if (!is_string($source['repository'] ?? null) || !preg_match('#\Ahttps://[A-Za-z0-9.-]+(?::\d+)?/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\z#', $source['repository'])) {
                throw new Refusal("the catalogue's entry for {$where} has a repository that is not https://host/owner/repo (34).");
            }

            if (!is_string($source['commit'] ?? null) || !preg_match('/\A[0-9a-f]{40}\z/', $source['commit'])) {
                throw new Refusal("the catalogue's entry for {$where} pins no full 40-character commit (34).");
            }

            $path = $source['path'] ?? null;

            if (!is_string($path) || !preg_match('#\A(?:\.|[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*)\z#', $path) || in_array('..', explode('/', $path), true)) {
                throw new Refusal("the catalogue's entry for {$where} has a path that is not '.' or a relative path without '..' (34).");
            }

            $source = ['repository' => $source['repository'], 'commit' => $source['commit'], 'path' => $path];
        } else {
            if (!is_string($source['url'] ?? null) || !preg_match('#\Ahttps://\S+\z#', $source['url'])) {
                throw new Refusal("the catalogue's entry for {$where} has a url that is not HTTPS (9, 34).");
            }

            $source = ['url' => $source['url']];
        }

        if (!is_string($field('sha256')) || !preg_match('/\A[0-9a-f]{64}\z/', $field('sha256'))) {
            throw new Refusal("the catalogue's entry for {$where} has no 64-character sha256 (34).");
        }

        $provenOn = $field('proven-on') ?? [];

        if (!is_array($provenOn) || array_filter($provenOn, static fn (mixed $v): bool => !is_string($v) || !preg_match(Format::VERSION, $v)) !== []) {
            throw new Refusal("the catalogue's entry for {$where} has a proven-on that is not a list of X.Y.Z (34).");
        }

        return new self(
            $name,
            $version,
            $field('waffle'),
            $field('form'),
            $source,
            $field('sha256'),
            array_values($provenOn),
            $text($field('reviewed-by')) ? $field('reviewed-by') : null,
            $text($field('yanked')) ? $field('yanked') : null,
        );
    }

    /** Why this version may not be installed on this Waffle version, or null when it may (4, 5). */
    public function ineligible(string $waffleVersion): ?string
    {
        return match (true) {
            $this->yanked !== null => 'yanked: ' . Terminal::safe($this->yanked),
            $this->reviewedBy === null => 'not reviewed yet',
            !Constraint::parse($this->waffle)->matches($waffleVersion) => "written for Waffle {$this->waffle}, and this app is on {$waffleVersion}",
            default => null,
        };
    }

    /** Where the files are, for people. */
    public function where(): string
    {
        return isset($this->source['url'])
            ? Terminal::safe($this->source['url'])
            : sprintf('%s at %s, path %s', $this->source['repository'], $this->source['commit'], $this->source['path']);
    }

    /** @return array<string,mixed> what modules.json and the staged record hold */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'form' => $this->form,
            'source' => $this->source,
            'sha256' => $this->sha256,
        ];
    }
}
