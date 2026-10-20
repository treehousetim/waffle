<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * The one format a record type's body is in (docs/content-model.md,
 * principle 2): Markdown for prose (posts, events), blocks for pages. Each
 * format checks what staff send, stores it as text, and gives it back as the
 * API shows it.
 */
enum BodyFormat: string
{
    case Markdown = 'markdown';
    case Blocks = 'blocks';

    /**
     * What is stored: the Markdown, or the blocks as JSON.
     *
     * @throws InvalidContent
     */
    public function check(mixed $raw): string
    {
        return match ($this) {
            self::Markdown => Markdown::check($raw),
            self::Blocks => json_encode(Blocks::check($raw ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /** @return string|list<array<string,mixed>> the body as the API gives it */
    public function present(string $stored): string|array
    {
        if ($this === self::Markdown) {
            return $stored;
        }

        /** @var list<array<string,mixed>> $blocks */
        $blocks = $stored === '' ? [] : json_decode($stored, true, 32, JSON_THROW_ON_ERROR);

        return $blocks;
    }

    /** Whether there is nothing to publish (1.). */
    public function isEmpty(string $stored): bool
    {
        return $this === self::Markdown ? trim($stored) === '' : $this->present($stored) === [];
    }
}
