<?php

declare(strict_types=1);

use App\Domain\Content\ChangeNotice;
use App\Domain\Ports\QueuePort;
use Tests\Support\E2EArtifact;
use Tests\TestCase;

/*
 * Publishing a post end to end (docs/content.md): an editor drafts it, the
 * site sees nothing; publishes it, the site sees it and a change notice waits
 * in the queue; renames it, and the site keeps the old version until it is
 * published again, then gets the old slug to redirect. Ends by writing
 * coverage/e2e/publishing-journey.json.
 */

it('takes a post from draft to the site, through a rename, and records what the site saw', function (): void {
    $queue = TestCase::container()->get(QueuePort::class);
    $queue->enqueued = [];
    $artifact = new E2EArtifact('publishing-journey');
    $site = fn (): array => array_column($this->json($this->get('/api/site/posts'))['data']['records'], null, 'slug');
    $this->asAdministrator();
    $post = ['summary' => 'What we learned this year.', 'body' => 'A year of building.'];

    $id = $this->json($this->post('/api/content/posts', ['title' => 'Year in review'] + $post))['data']['record']['id'];
    $whileDraft = array_keys($site());

    $this->post("/api/content/posts/{$id}/publish");
    $published = $site()['year-in-review'] ?? null;

    $this->put("/api/content/posts/{$id}", ['slug' => 'year-in-review-2026', 'title' => 'Year in review, 2026'] + $post);
    $beforeRepublish = array_keys($site());
    $this->post("/api/content/posts/{$id}/publish");
    $renamed = $site()['year-in-review-2026'] ?? null;
    $notices = array_map(static fn (array $queued): array => $queued['job']->changes, array_values(array_filter(
        $queue->enqueued,
        static fn (array $queued): bool => $queued['job'] instanceof ChangeNotice,
    )));

    $artifact
        ->fact('site while draft', $whileDraft)
        ->fact('site after publishing', $published['title'] ?? null)
        ->fact('site after renaming, before republishing', $beforeRepublish)
        ->fact('site after republishing', $renamed === null ? null : ['title' => $renamed['title'], 'previous_slugs' => $renamed['previous_slugs']])
        ->fact('change notices queued', $notices)
        ->check('a draft never reaches the site', !in_array('year-in-review', $whileDraft, true))
        ->check('a published post reaches the site', ($published['title'] ?? null) === 'Year in review')
        ->check('an unpublished edit leaves the site as it was', in_array('year-in-review', $beforeRepublish, true) && !in_array('year-in-review-2026', $beforeRepublish, true))
        ->check('the old slug goes to the site to redirect', ($renamed['previous_slugs'] ?? []) === ['year-in-review'])
        ->check('each publish queued a notice naming the pages it touched', $notices === [['posts' => ['year-in-review']], ['posts' => ['year-in-review', 'year-in-review-2026']]]);

    $written = json_decode((string) file_get_contents($artifact->write()), true);

    expect($written['passed'])->toBeTrue()
        ->and($written['sha256'])->toBe($artifact->digest());
});
