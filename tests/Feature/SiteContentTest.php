<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Controllers\SiteContentController;
use App\Domain\Content\RecordFields;
use App\Domain\Content\RecordType;
use App\Domain\Content\RecordTypes;
use App\Libraries\Content\Publishing;
use App\Models\Content\RecordRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fakes\FrozenClock;
use Tests\TestCase;

/*
 * The site API for content (docs/content.md): what a site build reads.
 * Public, read-only, and only ever published revisions.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock();
    $this->publishing = new Publishing(TestCase::container()->get(RecordRepository::class), $this->clock, new FakeQueue());
    $this->posts = TestCase::container()->get(RecordTypes::class)->find('posts');
    $this->editor = Factory::createUser(Role::Administrator);
});

/** @return array<string,mixed> */
function sitePost(string $title): array
{
    return ['title' => $title, 'summary' => "About {$title}.", 'body' => "All about {$title}."];
}

it('gives the site each published post as last published, with old slugs to redirect (3)', function (): void {
    $post = $this->publishing->create($this->posts, sitePost('Agents night') + ['slug' => 'site-agents']);
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $this->clock->advance('+1 day');
    $this->publishing->update($this->posts, $post->id, sitePost('Agents night, live') + ['slug' => 'site-agents-live']);
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $this->publishing->update($this->posts, $post->id, sitePost('Not yet published') + ['slug' => 'site-agents-live']);

    $live = array_column($this->json($this->get('/api/site/posts'))['data']['records'], null, 'slug')['site-agents-live'];

    expect($live)->toBe([
        'id' => $post->id,
        'slug' => 'site-agents-live',
        'title' => 'Agents night, live',
        'summary' => 'About Agents night, live.',
        'body' => 'All about Agents night, live.',
        'seo_title' => null,
        'seo_description' => null,
        'published_at' => '2026-01-01T12:00:00Z',
        'updated_at' => '2026-01-02T12:00:00Z',
        'previous_slugs' => ['site-agents'],
    ]);
});

it('leaves drafts and retired posts out, and lists retired slugs for 410s', function (): void {
    $draft = $this->publishing->create($this->posts, sitePost('Draft') + ['slug' => 'site-draft']);
    $gone = $this->publishing->create($this->posts, sitePost('Gone') + ['slug' => 'site-gone']);
    $this->publishing->publish($this->posts, $gone->id, $this->editor);
    $this->publishing->update($this->posts, $gone->id, sitePost('Gone') + ['slug' => 'site-gone-renamed']);
    $this->publishing->retire($this->posts, $gone->id);

    $slugs = array_column($this->json($this->get('/api/site/posts'))['data']['records'], 'slug');
    $retired = $this->json($this->get('/api/site/posts/retired'))['data']['slugs'];

    expect($slugs)->not->toContain('site-draft', 'site-gone', 'site-gone-renamed')
        ->and($retired)->toContain('site-gone', 'site-gone-renamed')
        ->and($retired)->not->toContain('site-draft')
        ->and($draft->slug)->toBe('site-draft');
});

it('answers anybody, signed in or not, and never a session', function (): void {
    $response = $this->get('/api/site/posts');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Set-Cookie'))->toBe('')
        ->and($this->json($response)['success'])->toBeTrue();
});

it('has no site API for a type it does not publish', function (): void {
    expect($this->get('/api/site/users')->getStatusCode())->toBe(404);
});

it('reads a revision published before its type gained a field as having none (27)', function (): void {
    $this->asAdministrator();
    $id = $this->json($this->post('/api/content/posts', ['title' => 'Before the field', 'summary' => 'S', 'body' => 'Text.']))['data']['record']['id'];
    $this->post("/api/content/posts/{$id}/publish");
    // Posts gain a field after that publish: its revision has no value for it.
    $this->swap(RecordTypes::class, new RecordTypes(new RecordType('posts', 'post', 'post', new class () implements RecordFields {
        public function names(): array
        {
            return ['venue'];
        }

        public function parse(array $input): array
        {
            return ['venue' => null];
        }

        public function present(array $stored): array
        {
            return ['venue' => $stored['venue']];
        }

        public function publishProblems(array $stored): array
        {
            return [];
        }

        public function images(array $stored): array
        {
            return [];
        }
    }), TestCase::container()->get('record_type:pages')));
    $this->swap(SiteContentController::class, TestCase::container()->make(SiteContentController::class));

    $site = array_column($this->json($this->get('/api/site/posts'))['data']['records'], null, 'slug')['before-the-field'];

    expect($site)->toHaveKey('venue')
        ->and($site['venue'])->toBeNull();
});
