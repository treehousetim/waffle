<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * A record type's own fields, beyond the ones every record has
 * (docs/content-model.md, "One table per record type"). Each is a real column,
 * `<table>_<name>`, checked by its type's parser here in Domain/.
 */
interface RecordFields
{
    /**
     * The column suffixes, in the order the API lists them.
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * The fields from a request body, checked, as the values to store.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,string|int|null> name => value, one per name
     *
     * @throws InvalidContent
     */
    public function parse(array $input): array;

    /**
     * Stored values as the API and the site get them.
     *
     * @param array<string,string|int|null> $stored
     *
     * @return array<string,mixed>
     */
    public function present(array $stored): array;

    /**
     * Why stored values are not fit to publish; empty when they are.
     *
     * @param array<string,string|int|null> $stored
     *
     * @return list<string>
     */
    public function publishProblems(array $stored): array;

    /**
     * The images from the media library these fields name, by the name the
     * site gets each under (`cover` => 7, or null when none is chosen). An
     * image named here is checked like one in a block: it must exist to save,
     * be described to publish, and becomes public when published
     * (docs/content.md, "Media").
     *
     * @param array<string,string|int|null> $stored
     *
     * @return array<string,int|null>
     */
    public function images(array $stored): array;
}
