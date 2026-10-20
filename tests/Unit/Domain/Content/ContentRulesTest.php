<?php

declare(strict_types=1);

use App\Domain\Content\ContentText;
use App\Domain\Content\Draft;
use App\Domain\Content\InvalidContent;
use App\Domain\Content\Markdown;
use App\Domain\Content\NoFields;
use App\Domain\Content\PublishBar;
use App\Domain\Content\PublishStatus;
use App\Domain\Content\RecordFields;
use App\Domain\Content\RecordType;
use App\Domain\Content\RecordTypes;
use App\Domain\Content\Slug;
use App\Domain\Status\IllegalTransition;

/* The content slice's rules (docs/content.md). The numbers are the failure list's. */

/** A record type with one own field, which publishing requires. */
function typeWithVenue(): RecordType
{
    return new RecordType('events', 'event', 'event', new class () implements RecordFields {
        public function names(): array
        {
            return ['venue'];
        }

        public function parse(array $input): array
        {
            return ['venue' => ContentText::summary($input['venue'] ?? '')];
        }

        public function present(array $stored): array
        {
            return ['venue' => $stored['venue']];
        }

        public function publishProblems(array $stored): array
        {
            return $stored['venue'] === '' ? ['It needs a venue.'] : [];
        }
    });
}

it('moves a record only along its declared edges (2)', function (): void {
    expect(PublishStatus::Draft->allowedNext())->toBe([PublishStatus::Published])
        ->and(PublishStatus::Published->allowedNext())->toBe([PublishStatus::Retired])
        ->and(PublishStatus::Retired->allowedNext())->toBe([PublishStatus::Published])
        ->and(fn () => PublishStatus::Draft->assertCanTransitionTo(PublishStatus::Retired))
        ->toThrow(IllegalTransition::class, "A record cannot go from 'draft' to 'retired'.");
});

it('accepts a slug of lowercase letters, digits and single dashes (8)', function (): void {
    expect(Slug::check(' summer-social-2026 '))->toBe('summer-social-2026')
        ->and(Slug::check(str_repeat('a', 100)))->toBe(str_repeat('a', 100));
});

it('refuses any other slug (8)', function (mixed $slug, string $message): void {
    expect(fn () => Slug::check($slug))->toThrow(InvalidContent::class, $message);
})->with([
    'empty' => ['', 'The slug cannot be empty.'],
    'not text' => [['a'], 'The slug cannot be empty.'],
    'too long' => [str_repeat('a', 101), 'The slug can be at most 100 characters.'],
    'capitals' => ['Summer', 'The slug can hold only'],
    'a double dash' => ['summer--social', 'The slug can hold only'],
    'a leading dash' => ['-summer', 'The slug can hold only'],
    'a slash' => ['events/summer', 'The slug can hold only'],
    'a space' => ['summer social', 'The slug can hold only'],
]);

it('suggests a slug from a title', function (string $title, string $slug): void {
    expect(Slug::suggest($title))->toBe($slug)
        ->and(Slug::check(Slug::suggest($title)))->toBe($slug);
})->with([
    'words' => ['Summer Social 2026!', 'summer-social-2026'],
    'accents' => ['Café Night — Wichita', 'cafe-night-wichita'],
    'nothing usable' => ['東京', 'untitled'],
    'very long' => [str_repeat('word ', 40), substr(rtrim(str_repeat('word-', 18), '-'), 0, 89)],
]);

it('keeps titles, summaries and SEO text within their rules (10)', function (): void {
    expect(ContentText::title('  A night of agents  '))->toBe('A night of agents')
        ->and(ContentText::summary(null))->toBe('')
        ->and(ContentText::seoTitle(''))->toBeNull()
        ->and(ContentText::seoTitle('Agents'))->toBe('Agents')
        ->and(ContentText::seoDescription(null))->toBeNull()
        ->and(ContentText::multiline("one\r\n\ttwo", 'body', 10))->toBe("one\n\ttwo")
        ->and(ContentText::optionalLine(' ', 'venue', 10))->toBeNull()
        ->and(ContentText::optionalLine(' Hall ', 'venue', 10))->toBe('Hall');
});

it('refuses text that breaks the rules (10)', function (Closure $check, string $message): void {
    expect($check)->toThrow(InvalidContent::class, $message);
})->with([
    'an empty title' => [fn () => ContentText::title('  '), 'The title cannot be empty.'],
    'a missing title' => [fn () => ContentText::title(null), 'Send the title as text.'],
    'a long title' => [fn () => ContentText::title(str_repeat('a', 151)), 'The title can be at most 150 characters.'],
    'a long summary' => [fn () => ContentText::summary(str_repeat('a', 301)), 'The summary can be at most 300 characters.'],
    'a long SEO title' => [fn () => ContentText::seoTitle(str_repeat('a', 61)), 'The SEO title can be at most 60 characters.'],
    'a long SEO description' => [fn () => ContentText::seoDescription(str_repeat('a', 161)), 'The SEO description can be at most 160 characters.'],
    'a line break in a title' => [fn () => ContentText::title("A\nB"), 'The title holds characters that cannot be shown.'],
    'a control character in a body' => [fn () => ContentText::multiline("a\x07b", 'body', 10), 'The body holds characters that cannot be shown.'],
    'a long body' => [fn () => ContentText::multiline(str_repeat('a', 11), 'body', 10), 'The body can be at most 10 characters.'],
    'not UTF-8' => [fn () => ContentText::summary("\xC3\x28"), 'The summary is not valid UTF-8.'],
]);

it('takes Markdown with safe links, and code that shows anything (9)', function (): void {
    $body = <<<'MD'
        ## Agents night

        #hashtag is not a heading, and neither is this ### Level three

        See [the calendar](/events), [below](#rsvp), [the slides](https://example.com/s),
        <https://example.com> or [write to us](mailto:hello@example.com).

        [ref]: https://example.com/ref

        Inline `<script>alert(1)</script>` is shown as code, and so is:

        ```html
        <div onclick="x()">![x](javascript:alert(1))</div>
        ```
        MD;

    expect(Markdown::check($body))->toBe($body)
        ->and(Markdown::check(null))->toBe('');
});

it('refuses raw HTML, images and unsafe links in Markdown (9)', function (string $body, string $message): void {
    expect(fn () => Markdown::check($body))->toThrow(InvalidContent::class, $message);
})->with([
    'a tag' => ['Hello <b>there</b>', 'The body holds HTML. Write it in Markdown instead.'],
    'a script' => ["Text\n\n<script>alert(1)</script>", 'The body holds HTML.'],
    'a comment' => ['<!-- hidden -->', 'The body holds HTML.'],
    'an http autolink' => ['<http://example.com>', 'The body holds HTML.'],
    'an unclosed fence hides nothing before it' => ["<i>x</i>\n```\ncode", 'The body holds HTML.'],
    'an image' => ['![a cat](https://example.com/cat.jpg)', 'The body holds an image.'],
    'a top-level heading' => ["Intro\n\n# Agenda", 'The body holds a top-level heading'],
    'an empty top-level heading' => ['#', 'The body holds a top-level heading'],
    'an underlined top-level heading' => ["Agenda\n======", 'The body holds a top-level heading'],
    'javascript' => ['[x](javascript:alert(1))', 'The link to “javascript:alert(1” is not allowed'],
    'data' => ['[x](data:text/html,hi)', 'The link to “data:text/html,hi” is not allowed'],
    'plain http' => ['[x](http://example.com)', 'The link to “http://example.com” is not allowed'],
    'protocol-relative' => ['[x](//evil.example)', 'The link to “//evil.example” is not allowed'],
    'a relative path' => ['[x](events)', 'The link to “events” is not allowed'],
    'a bad reference' => ["[x][r]\n\n[r]: javascript:alert(1)", 'The link to “javascript:alert(1)” is not allowed'],
    'a mailto without an address' => ['[x](mailto:nobody)', 'The link to “mailto:nobody” is not allowed'],
    'HTML between escaped backticks' => ['\\`<img src=x onerror=alert(1)>\\`', 'The body holds HTML.'],
    'HTML in a code span broken by a blank line' => ["`a\n\n<b>x</b>\n\n`", 'The body holds HTML.'],
    'a bad reference in a quotation' => ["[x][r]\n\n> [r]: javascript:alert(1)", 'The link to “javascript:alert(1)” is not allowed'],
    'a bad reference in a list' => ["[x][r]\n\n- [r]: javascript:alert(1)", 'The link to “javascript:alert(1)” is not allowed'],
]);

it('declares only identifiers that can name a table or column (15)', function (string $name, string $singular): void {
    expect(fn () => new RecordType($name, $singular, 'x'))->toThrow(InvalidArgumentException::class, 'cannot name a table or column.');
})->with([
    'a quote' => ["posts'", 'post'],
    'a space' => ['posts', 'one post'],
    'capitals' => ['Posts', 'post'],
    'empty' => ['', 'post'],
]);

it('names the tables and columns of a record type', function (): void {
    $type = new RecordType('posts', 'post', 'post');

    expect($type->column('title'))->toBe('posts_title')
        ->and($type->revisions())->toBe('posts_revisions')
        ->and($type->slugs())->toBe('posts_slugs')
        ->and($type->owner($type->revisions()))->toBe('posts_revisions_post_id')
        ->and($type->fields)->toBeInstanceOf(NoFields::class)
        ->and((new NoFields())->present([]))->toBe([])
        ->and((new NoFields())->parse(['x' => 1]))->toBe([])
        ->and(fn () => typeWithVenue()->fields->names())->not->toThrow(Throwable::class);
});

it('finds record types by name only', function (): void {
    $posts = new RecordType('posts', 'post', 'post');
    $types = new RecordTypes($posts, typeWithVenue());

    expect($types->find('posts'))->toBe($posts)
        ->and($types->find('users'))->toBeNull()
        ->and(array_map(static fn (RecordType $t): string => $t->name, $types->all()))->toBe(['posts', 'events']);
});

it('holds a record back until it has a title, summary, body and its own required fields (1)', function (): void {
    $type = typeWithVenue();

    expect(PublishBar::problems($type, 'T', 'S', 'B', ['venue' => 'Hall']))->toBe([])
        ->and(PublishBar::problems($type, 'T', ' ', '', ['venue' => '']))
        ->toBe(['It needs a summary.', 'It needs a body.', 'It needs a venue.']);
});

it('reads a draft from a request, with its own fields', function (): void {
    $draft = Draft::parse(typeWithVenue(), ['title' => 'Agents', 'slug' => '', 'body' => 'Hi', 'venue' => 'Hall'], false);

    expect($draft->slug)->toBeNull()
        ->and($draft->title)->toBe('Agents')
        ->and($draft->summary)->toBe('')
        ->and($draft->seoTitle)->toBeNull()
        ->and($draft->fields)->toBe(['venue' => 'Hall'])
        ->and(Draft::parse(typeWithVenue(), ['title' => 'A', 'slug' => 'a-b'], false)->slug)->toBe('a-b')
        ->and(fn () => Draft::parse(typeWithVenue(), ['title' => 'A'], true))->toThrow(InvalidContent::class, 'The slug cannot be empty.');
});
