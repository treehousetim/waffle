<?php

declare(strict_types=1);

use Tests\TestCase;

/*
 * The content slice through the router (docs/content.md). AccessGridTest
 * checks 401 and 403 for every route and level; these check what each route
 * does. The numbers are the failure list's.
 */

/** @return array<string,mixed> */
function postBody(array $overrides = []): array
{
    return [...['title' => 'Agents night', 'summary' => 'Building agents together.', 'body' => 'Bring a [laptop](/kit).'], ...$overrides];
}

it('creates, edits, publishes, retires and restores a post', function (): void {
    $this->asAdministrator();

    $created = $this->post('/api/content/posts', postBody());
    $id = $this->json($created)['data']['record']['id'];
    $published = $this->post("/api/content/posts/{$id}/publish");
    $edited = $this->put("/api/content/posts/{$id}", postBody(['slug' => 'agents-night-2026', 'title' => 'Agents night 2026']));
    $retired = $this->post("/api/content/posts/{$id}/retire");
    $revision = $this->json($published)['data']['revisions'][0]['id'];
    $restored = $this->post("/api/content/posts/{$id}/revisions/{$revision}/restore");
    $shown = $this->json($this->get("/api/content/posts/{$id}"))['data'];

    expect($created->getStatusCode())->toBe(201)
        ->and($this->json($created)['data']['record'])->toMatchArray(['type' => 'posts', 'status' => 'draft', 'slug' => 'agents-night', 'seo_title' => null])
        ->and($this->json($published)['data']['record']['status'])->toBe('published')
        ->and($this->json($edited)['data']['record']['title'])->toBe('Agents night 2026')
        ->and($this->json($retired)['data']['record']['status'])->toBe('retired')
        ->and($this->json($restored)['data']['record'])->toMatchArray(['status' => 'published', 'title' => 'Agents night', 'slug' => 'agents-night-2026'])
        ->and(array_column($shown['revisions'], 'title'))->toBe(['Agents night', 'Agents night'])
        ->and(array_column($shown['slugs'], 'slug'))->toBe(['agents-night-2026', 'agents-night']);
});

it('lists posts, all or by status', function (): void {
    $this->asAdministrator();
    $draft = $this->json($this->post('/api/content/posts', postBody(['title' => 'Draft one'])))['data']['record']['id'];
    $live = $this->json($this->post('/api/content/posts', postBody(['title' => 'Live one'])))['data']['record']['id'];
    $this->post("/api/content/posts/{$live}/publish");

    $all = array_column($this->json($this->get('/api/content/posts'))['data']['records'], 'id');
    $published = array_column($this->json($this->get('/api/content/posts', ['status' => 'published']))['data']['records'], 'id');

    expect($all)->toContain($draft, $live)
        ->and($published)->toContain($live)
        ->and($published)->not->toContain($draft)
        ->and($this->get('/api/content/posts', ['status' => 'live'])->getStatusCode())->toBe(422);
});

it('refuses content it will not take, and a post not fit to publish (1, 9, 10)', function (): void {
    $this->asAdministrator();
    $draft = $this->json($this->post('/api/content/posts', ['title' => 'Bare']))['data']['record']['id'];

    $html = $this->post('/api/content/posts', postBody(['body' => '<b>hi</b>']));
    $long = $this->post('/api/content/posts', postBody(['title' => str_repeat('a', 151)]));
    $bare = $this->post("/api/content/posts/{$draft}/publish");

    expect($html->getStatusCode())->toBe(422)
        ->and($this->json($html)['error'])->toBe('The body holds HTML. Write it in Markdown instead.')
        ->and($long->getStatusCode())->toBe(422)
        ->and($bare->getStatusCode())->toBe(422)
        ->and($this->json($bare)['error'])->toBe('This cannot be published yet. It needs a summary. It needs a body.');
});

it('refuses a move the state machine does not allow with 409 (2)', function (): void {
    $this->asAdministrator();
    $draft = $this->json($this->post('/api/content/posts', postBody()))['data']['record']['id'];

    $retire = $this->post("/api/content/posts/{$draft}/retire");

    expect($retire->getStatusCode())->toBe(409)
        ->and($this->json($retire)['error'])->toBe("A record cannot go from 'draft' to 'retired'.");
});

it('answers 404 for a type, record or revision that does not exist (13)', function (string $method, string $uri): void {
    $this->asAdministrator();

    $response = $method === 'GET' ? $this->get($uri) : $this->post($uri);

    expect($response->getStatusCode())->toBe(404);
})->with([
    'an unknown type' => ['GET', '/api/content/users'],
    'an unknown type, written to' => ['POST', '/api/content/users'],
    'a missing record' => ['GET', '/api/content/posts/999999'],
    'an id that is not a number' => ['GET', '/api/content/posts/abc'],
    'publishing a missing record' => ['POST', '/api/content/posts/999999/publish'],
    'retiring a missing record' => ['POST', '/api/content/posts/999999/retire'],
    'restoring a missing revision' => ['POST', '/api/content/posts/999999/revisions/1/restore'],
]);

it('restores a revision that holds a field the type has since lost (27)', function (): void {
    $this->asAdministrator();
    $id = $this->json($this->post('/api/content/posts', ['title' => 'Older post', 'summary' => 'S', 'body' => 'Text.']))['data']['record']['id'];
    $this->post("/api/content/posts/{$id}/publish");
    TestCase::pdo()->prepare("UPDATE posts_revisions SET posts_revisions_content = JSON_SET(posts_revisions_content, '$.fields', JSON_OBJECT('capacity', 40)) WHERE posts_revisions_post_id = ?")->execute([$id]);
    $revision = $this->json($this->get("/api/content/posts/{$id}"))['data']['revisions'][0]['id'];

    $restored = $this->post("/api/content/posts/{$id}/revisions/{$revision}/restore");

    expect($restored->getStatusCode())->toBe(200)
        ->and($this->json($restored)['data']['record'])->not->toHaveKey('capacity');
});
