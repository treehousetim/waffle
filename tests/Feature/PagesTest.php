<?php

declare(strict_types=1);

/*
 * Pages through the router (docs/content.md, "Blocks"): a record type whose
 * body is blocks. The numbers are the failure list's.
 */

/** @return list<array<string,mixed>> */
function pageBlocks(): array
{
    return [
        ['type' => 'heading', 'level' => 2, 'text' => 'Who we are'],
        ['type' => 'text', 'markdown' => 'People who **build things together**.'],
        ['type' => 'cta', 'label' => 'See events', 'path' => '/events/'],
    ];
}

it('saves, publishes and serves a page of blocks, as blocks (1, 22)', function (): void {
    $this->asAdministrator();

    $created = $this->post('/api/content/pages', ['title' => 'About', 'summary' => 'What the meetup is.', 'body' => pageBlocks()]);
    $id = $this->json($created)['data']['record']['id'];
    $published = $this->post("/api/content/pages/{$id}/publish");
    $site = array_column($this->json($this->get('/api/site/pages'))['data']['records'], null, 'slug')['about'];

    expect($created->getStatusCode())->toBe(201)
        ->and($this->json($created)['data']['record']['body'][0])->toBe(['type' => 'heading', 'level' => 2, 'text' => 'Who we are'])
        ->and($this->json($published)['data']['record']['status'])->toBe('published')
        ->and($site['body'])->toBe([
            ['type' => 'heading', 'level' => 2, 'text' => 'Who we are'],
            ['type' => 'text', 'markdown' => 'People who **build things together**.'],
            ['type' => 'cta', 'label' => 'See events', 'path' => '/events/', 'note' => null],
        ]);
});

it('refuses a block outside the set, and a page with no blocks to publish (1, 22)', function (): void {
    $this->asAdministrator();

    $bad = $this->post('/api/content/pages', ['title' => 'Bad', 'body' => [['type' => 'html', 'html' => '<b>x</b>']]]);
    $empty = $this->json($this->post('/api/content/pages', ['title' => 'Empty', 'summary' => 'S']))['data']['record'];
    $publish = $this->post("/api/content/pages/{$empty['id']}/publish");

    expect($bad->getStatusCode())->toBe(422)
        ->and($this->json($bad)['error'])->toBe("Block 1 is of a type there is none of: 'html'.")
        ->and($empty['body'])->toBe([])
        ->and($this->json($publish)['error'])->toBe('This cannot be published yet. It needs a body.');
});
