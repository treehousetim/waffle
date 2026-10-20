<?php

declare(strict_types=1);

use App\Domain\Content\Blocks;
use App\Domain\Content\BodyFormat;
use App\Domain\Content\InvalidContent;

/* Block bodies (docs/content.md, "Blocks"). The numbers are the failure list's. */

/** @return list<array<string,mixed>> one of every block type */
function everyBlock(): array
{
    return [
        ['type' => 'heading', 'level' => 2, 'text' => ' Who we are '],
        ['type' => 'text', 'markdown' => 'We meet **monthly**. See [events](/events/).'],
        ['type' => 'quote', 'text' => 'A sample quotation.', 'cite' => 'A sample member'],
        ['type' => 'quote', 'text' => 'No one said this.'],
        ['type' => 'callout', 'tone' => 'warning', 'markdown' => 'Bring your own laptop.'],
        ['type' => 'faq', 'items' => [['question' => 'Is it free?', 'answer' => 'Yes.']]],
        ['type' => 'cta', 'label' => 'See events', 'path' => '/events/'],
        ['type' => 'cta', 'label' => 'Join', 'path' => '/join/', 'note' => 'It takes a minute.'],
        ['type' => 'table', 'columns' => ['Day', 'Time'], 'rows' => [['Thursday', '6:30 PM'], ['Friday', ' ']]],
    ];
}

it('takes every block type, trimmed, optional fields as null (22)', function (): void {
    $blocks = Blocks::check(everyBlock());

    expect($blocks[0])->toBe(['type' => 'heading', 'level' => 2, 'text' => 'Who we are'])
        ->and($blocks[2])->toBe(['type' => 'quote', 'text' => 'A sample quotation.', 'cite' => 'A sample member'])
        ->and($blocks[3])->toBe(['type' => 'quote', 'text' => 'No one said this.', 'cite' => null])
        ->and($blocks[6])->toBe(['type' => 'cta', 'label' => 'See events', 'path' => '/events/', 'note' => null])
        ->and($blocks[8])->toBe(['type' => 'table', 'columns' => ['Day', 'Time'], 'rows' => [['Thursday', '6:30 PM'], ['Friday', '']]])
        ->and(Blocks::check([]))->toBe([]);
});

it('refuses a block that is not one of the set, or has fields it should not (22)', function (array $blocks, string $message): void {
    expect(fn () => Blocks::check($blocks))->toThrow(InvalidContent::class, $message);
})->with([
    'not a list' => [['type' => 'text'], 'The body must be a list of blocks.'],
    'not an object' => [['text'], 'Block 1 is not a block.'],
    'an unknown type' => [[['type' => 'html', 'html' => '<b>x</b>']], "Block 1 is of a type there is none of: 'html'."],
    'an extra field' => [[['type' => 'heading', 'level' => 2, 'text' => 'A', 'style' => 'red']], 'Block 1 (heading) has a field it does not take: style.'],
    'a heading at level 1' => [[['type' => 'heading', 'level' => 1, 'text' => 'A']], 'Block 1 (heading): the level must be 2, 3 or 4.'],
    'an empty heading' => [[['type' => 'heading', 'level' => 2, 'text' => ' ']], 'Block 1 (heading): the text cannot be empty.'],
    'raw HTML in text' => [[['type' => 'text', 'markdown' => '<script>x</script>']], 'Block 1 (text): the text holds HTML.'],
    'a heading inside text' => [[['type' => 'text', 'markdown' => "## Sneaky"]], 'Block 1 (text): the text holds a heading'],
    'an unsafe link' => [[['type' => 'callout', 'tone' => 'note', 'markdown' => '[x](javascript:alert(1))']], 'Block 1 (callout): the link to'],
    'an unknown tone' => [[['type' => 'callout', 'tone' => 'danger', 'markdown' => 'x']], 'Block 1 (callout): the tone must be note or warning.'],
    'an empty faq' => [[['type' => 'faq', 'items' => []]], 'Block 1 (faq): it needs 1 to 50 questions.'],
    'a faq item without an answer' => [[['type' => 'faq', 'items' => [['question' => 'Q?']]]], 'Block 1 (faq), question 1: the answer cannot be empty.'],
    'a faq item with extras' => [[['type' => 'faq', 'items' => [['question' => 'Q?', 'answer' => 'A', 'x' => 1]]]], 'Block 1 (faq), question 1 has a field it does not take: x.'],
    'a cta off the site' => [[['type' => 'cta', 'label' => 'Go', 'path' => 'https://elsewhere.example/']], 'Block 1 (cta): the path must be a page on this site'],
    'a faq item that is not one' => [[['type' => 'faq', 'items' => ['Q?']]], 'Block 1 (faq), question 1 is not a question and answer.'],
    'rows that are not a list' => [[['type' => 'table', 'columns' => ['A'], 'rows' => 'x']], 'Block 1 (table): it can have at most 100 rows.'],
    'an underlined heading inside text' => [[['type' => 'text', 'markdown' => "Sneaky\n---"]], 'Block 1 (text): the text holds a heading'],
    'a ragged table' => [[['type' => 'table', 'columns' => ['A', 'B'], 'rows' => [['1']]]], 'Block 1 (table), row 1: it needs 2 cells.'],
    'a table with no columns' => [[['type' => 'table', 'columns' => [], 'rows' => []]], 'Block 1 (table): it needs 1 to 10 columns.'],
    'a table cell that is not text' => [[['type' => 'table', 'columns' => ['A'], 'rows' => [[['x']]]]], 'Block 1 (table), row 1: the cell must be text.'],
    'too many blocks' => [array_fill(0, 201, ['type' => 'heading', 'level' => 2, 'text' => 'A']), 'A body can have at most 200 blocks.'],
]);

it('stores a block body as JSON and gives it back as blocks', function (): void {
    $stored = BodyFormat::Blocks->check(everyBlock());

    expect(json_decode($stored, true))->toBe(Blocks::check(everyBlock()))
        ->and(BodyFormat::Blocks->present($stored))->toBe(Blocks::check(everyBlock()))
        ->and(BodyFormat::Blocks->present(''))->toBe([])
        ->and(BodyFormat::Blocks->isEmpty('[]'))->toBeTrue()
        ->and(BodyFormat::Blocks->isEmpty($stored))->toBeFalse()
        ->and(BodyFormat::Blocks->check(null))->toBe('[]')
        ->and(BodyFormat::Markdown->check(' Hi '))->toBe('Hi')
        ->and(BodyFormat::Markdown->present('Hi'))->toBe('Hi')
        ->and(BodyFormat::Markdown->isEmpty(' '))->toBeTrue();
});
