<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * Block bodies (docs/content.md, "Blocks"; docs/content-model.md): an ordered
 * list of typed blocks from one fixed set, each rendered on the site by its
 * own component. Anything outside the set, or a field a block does not take,
 * is refused (22.), so a block never carries HTML, a script or a style.
 * Images and galleries name media by id; whether those exist and are
 * described is the media library's to say (docs/content.md, "Media").
 */
final class Blocks
{
    public const int MAX_BLOCKS = 200;

    /** Every type, and the fields it takes. */
    private const array FIELDS = [
        'heading' => ['type', 'level', 'text'],
        'text' => ['type', 'markdown'],
        'quote' => ['type', 'text', 'cite'],
        'callout' => ['type', 'tone', 'markdown'],
        'faq' => ['type', 'items'],
        'cta' => ['type', 'label', 'path', 'note'],
        'table' => ['type', 'columns', 'rows'],
        'image' => ['type', 'media_id', 'caption'],
        'gallery' => ['type', 'media_ids', 'caption'],
    ];

    /**
     * Every media id the blocks name, in order, once each.
     *
     * @param list<array<string,mixed>> $blocks checked
     *
     * @return list<int>
     */
    public static function mediaIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            $ids = [...$ids, ...match ($block['type']) {
                'image' => [$block['media_id']],
                'gallery' => $block['media_ids'],
                default => [],
            }];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array<string,mixed>> the blocks, checked and normalised
     *
     * @throws InvalidContent
     */
    public static function check(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw InvalidContent::because('The body must be a list of blocks.');
        }

        if (count($raw) > self::MAX_BLOCKS) {
            throw InvalidContent::because('A body can have at most ' . self::MAX_BLOCKS . ' blocks.');
        }

        $blocks = [];

        foreach ($raw as $index => $block) {
            $blocks[] = self::block($block, $index + 1);
        }

        return $blocks;
    }

    /** @return array<string,mixed> */
    private static function block(mixed $block, int $number): array
    {
        if (!is_array($block) || array_is_list($block)) {
            throw InvalidContent::because("Block {$number} is not a block.");
        }

        $type = is_string($block['type'] ?? null) ? $block['type'] : '';

        if (!isset(self::FIELDS[$type])) {
            throw InvalidContent::because("Block {$number} is of a type there is none of: '{$type}'.");
        }

        $where = "Block {$number} ({$type})";
        self::onlyFields($block, self::FIELDS[$type], $where);

        try {
            return ['type' => $type, ...match ($type) {
                'heading' => self::heading($block),
                'text' => ['markdown' => Markdown::check($block['markdown'] ?? null, 'text', false, 20_000)],
                'quote' => ['text' => self::line($block['text'] ?? null, 'text', 1000), 'cite' => ContentText::optionalLine($block['cite'] ?? null, 'cite', 150)],
                'callout' => self::callout($block),
                'faq' => ['items' => self::faq($block['items'] ?? null, $where)],
                'cta' => self::cta($block),
                'table' => self::table($block, $where),
                'image' => ['media_id' => self::mediaId($block['media_id'] ?? null), 'caption' => ContentText::optionalLine($block['caption'] ?? null, 'caption', 300)],
                'gallery' => ['media_ids' => self::gallery($block['media_ids'] ?? null), 'caption' => ContentText::optionalLine($block['caption'] ?? null, 'caption', 300)],
            }];
        } catch (InvalidContent $e) {
            throw self::at($where, $e);
        }
    }

    /** @param array<string,mixed> $block @return array<string,mixed> */
    private static function heading(array $block): array
    {
        $level = $block['level'] ?? null;

        if (!in_array($level, [2, 3, 4], true)) {
            throw InvalidContent::because('The level must be 2, 3 or 4.');
        }

        return ['level' => $level, 'text' => self::line($block['text'] ?? null, 'text', ContentText::TITLE_MAX)];
    }

    /** @param array<string,mixed> $block @return array<string,mixed> */
    private static function callout(array $block): array
    {
        $tone = $block['tone'] ?? null;

        if (!in_array($tone, ['note', 'warning'], true)) {
            throw InvalidContent::because('The tone must be note or warning.');
        }

        return ['tone' => $tone, 'markdown' => Markdown::check($block['markdown'] ?? null, 'text', false, 5_000)];
    }

    /** @return list<array{question:string,answer:string}> */
    private static function faq(mixed $items, string $where): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 50) {
            throw InvalidContent::because('It needs 1 to 50 questions.');
        }

        $checked = [];

        foreach ($items as $index => $item) {
            $at = "{$where}, question " . ($index + 1);

            if (!is_array($item) || array_is_list($item)) {
                throw InvalidContent::because("{$at} is not a question and answer.");
            }

            self::onlyFields($item, ['question', 'answer'], $at);

            try {
                $checked[] = [
                    'question' => self::line($item['question'] ?? null, 'question', 300),
                    'answer' => self::required(Markdown::check($item['answer'] ?? null, 'answer', false, 5_000), 'answer'),
                ];
            } catch (InvalidContent $e) {
                throw self::at($at, $e);
            }
        }

        return $checked;
    }

    /** @param array<string,mixed> $block @return array<string,mixed> */
    private static function cta(array $block): array
    {
        $path = is_string($block['path'] ?? null) ? trim($block['path']) : '';

        if (preg_match('#^/(?:[a-z0-9]+(?:-[a-z0-9]+)*/)*$#', $path) !== 1) {
            throw InvalidContent::because('The path must be a page on this site, like /events/.');
        }

        return [
            'label' => self::line($block['label'] ?? null, 'label', 60),
            'path' => $path,
            'note' => ContentText::optionalLine($block['note'] ?? null, 'note', 200),
        ];
    }

    /** @param array<string,mixed> $block @return array{columns:list<string>,rows:list<list<string>>} */
    private static function table(array $block, string $where): array
    {
        $columns = $block['columns'] ?? null;
        $rows = $block['rows'] ?? null;

        if (!is_array($columns) || !array_is_list($columns) || count($columns) < 1 || count($columns) > 10) {
            throw InvalidContent::because('It needs 1 to 10 columns.');
        }

        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) {
            throw InvalidContent::because('It can have at most 100 rows.');
        }

        $checkedRows = [];

        foreach ($rows as $index => $row) {
            $at = "{$where}, row " . ($index + 1);

            if (!is_array($row) || !array_is_list($row) || count($row) !== count($columns)) {
                throw InvalidContent::because("{$at}: it needs " . count($columns) . ' cells.');
            }

            try {
                $checkedRows[] = array_map(static fn (mixed $cell): string => self::cell($cell), $row);
            } catch (InvalidContent $e) {
                throw self::at($at, $e);
            }
        }

        return ['columns' => array_map(static fn (mixed $column): string => self::line($column, 'column', 100), $columns), 'rows' => $checkedRows];
    }

    private static function mediaId(mixed $id): int
    {
        if (!is_int($id) || $id < 1) {
            throw InvalidContent::because('It needs an image from the media library.');
        }

        return $id;
    }

    /** @return list<int> */
    private static function gallery(mixed $ids): array
    {
        if (!is_array($ids) || !array_is_list($ids) || count($ids) < 2 || count($ids) > 24) {
            throw InvalidContent::because('A gallery needs 2 to 24 images.');
        }

        return array_map(self::mediaId(...), $ids);
    }

    private static function cell(mixed $cell): string
    {
        if (!is_string($cell)) {
            throw InvalidContent::because('The cell must be text.');
        }

        return ContentText::optionalLine($cell, 'cell', 300) ?? '';
    }

    /** One line of required text. */
    private static function line(mixed $raw, string $what, int $max): string
    {
        return self::required(ContentText::optionalLine($raw ?? '', $what, $max) ?? '', $what);
    }

    private static function required(string $value, string $what): string
    {
        if (trim($value) === '') {
            throw InvalidContent::because("The {$what} cannot be empty.");
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $block
     * @param list<string>        $fields
     */
    private static function onlyFields(array $block, array $fields, string $where): void
    {
        $extra = array_diff(array_keys($block), $fields);

        if ($extra !== []) {
            throw InvalidContent::because("{$where} has a field it does not take: " . implode(', ', $extra) . '.');
        }
    }

    /** The message, said of the block it is about: "Block 3 (faq): the answer…". */
    private static function at(string $where, InvalidContent $e): InvalidContent
    {
        $message = $e->getMessage();

        if (str_starts_with($message, 'Block ')) {
            return $e;
        }

        return InvalidContent::because("{$where}: " . lcfirst($message));
    }
}
