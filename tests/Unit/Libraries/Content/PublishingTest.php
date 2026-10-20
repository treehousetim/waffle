<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Domain\Content\InvalidContent;
use App\Domain\Content\PublishStatus;
use App\Domain\Content\RecordTypes;
use App\Domain\Status\IllegalTransition;
use App\Libraries\Content\ContentNotFound;
use App\Libraries\Content\Publishing;
use App\Models\Content\RecordRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fakes\FrozenClock;
use Tests\TestCase;

/*
 * Publishing's rules (docs/content.md) on the real `posts` tables. The
 * numbers are the failure list's.
 */

beforeEach(function (): void {
    // A clock of its own, so each test starts at the same instant.
    $this->clock = new FrozenClock();
    $this->records = TestCase::container()->get(RecordRepository::class);
    $this->queue = new FakeQueue();
    $this->publishing = new Publishing($this->records, $this->clock, $this->queue);
    $this->posts = TestCase::container()->get(RecordTypes::class)->find('posts');
    $this->editor = Factory::createUser(Role::Administrator);
});

/** @return array<string,mixed> a post fit to publish */
function post(array $overrides = []): array
{
    return [...['title' => 'Agents night', 'summary' => 'Building agents together.', 'body' => 'Bring a laptop.'], ...$overrides];
}

it('creates a draft with a slug made from its title, numbered past any slug ever taken (6)', function (): void {
    $first = $this->publishing->create($this->posts, post());
    $second = $this->publishing->create($this->posts, post());
    $this->publishing->update($this->posts, $first->id, post(['slug' => 'agents-night-renamed']));
    $third = $this->publishing->create($this->posts, post());

    expect($first->status)->toBe(PublishStatus::Draft)
        ->and([$first->slug, $second->slug, $third->slug])->toBe(['agents-night', 'agents-night-2', 'agents-night-3'])
        ->and($first->publishedAt)->toBeNull();
});

it('keeps a draft that is not yet fit to publish', function (): void {
    $draft = $this->publishing->create($this->posts, ['title' => 'Just an idea']);

    expect([$draft->summary, $draft->body])->toBe(['', ''])
        ->and(fn () => $this->publishing->publish($this->posts, $draft->id, $this->editor))
        ->toThrow(InvalidContent::class, 'This cannot be published yet. It needs a summary. It needs a body.');

    expect($this->records->find($this->posts, $draft->id)->status)->toBe(PublishStatus::Draft)
        ->and($this->records->revisions($this->posts, $draft->id))->toBe([]);
});

it('refuses a slug another record has or ever had (6)', function (): void {
    $taken = $this->publishing->create($this->posts, post(['slug' => 'taken']));
    $this->publishing->update($this->posts, $taken->id, post(['slug' => 'taken-now']));
    $other = $this->publishing->create($this->posts, post(['slug' => 'other']));

    expect(fn () => $this->publishing->create($this->posts, post(['slug' => 'taken'])))
        ->toThrow(InvalidContent::class, 'The slug “taken” is taken')
        ->and(fn () => $this->publishing->update($this->posts, $other->id, post(['slug' => 'taken'])))
        ->toThrow(InvalidContent::class, 'The slug “taken” is taken')
        ->and(fn () => $this->publishing->update($this->posts, $other->id, post(['slug' => 'taken-now'])))
        ->toThrow(InvalidContent::class, 'The slug “taken-now” is taken');
});

it('keeps every slug a record has had, with one current, and lets it take its own back (7)', function (): void {
    $post = $this->publishing->create($this->posts, post(['slug' => 'first']));
    $this->clock->advance('+1 minute');
    $this->publishing->update($this->posts, $post->id, post(['slug' => 'second']));
    $this->clock->advance('+1 minute');
    $back = $this->publishing->update($this->posts, $post->id, post(['slug' => 'first']));

    $history = $this->records->slugHistory($this->posts, $post->id);

    expect($back->slug)->toBe('first')
        ->and(array_column($history, 'slug'))->toBe(['first', 'second'])
        ->and(array_column($history, 'replaced_at'))->toBe([null, '2026-01-01 12:02:00'])
        ->and($this->records->slugOwner($this->posts, 'second'))->toBe($post->id)
        ->and($this->records->slugOwner($this->posts, 'never'))->toBeNull();
});

it('publishes with a revision of exactly what was published (1, 4)', function (): void {
    $post = $this->publishing->create($this->posts, post(['seo_title' => 'Agents']));
    $this->clock->advance('+1 hour');
    $published = $this->publishing->publish($this->posts, $post->id, $this->editor);
    $revision = $this->records->latestRevision($this->posts, $post->id);

    expect($published->status)->toBe(PublishStatus::Published)
        ->and($published->publishedAt)->toBe('2026-01-01 13:00:00')
        ->and($published->contentChangedAt)->toBe('2026-01-01 13:00:00')
        ->and($revision->content)->toBe($post->content())
        ->and($revision->publishedBy)->toBe($this->editor)
        ->and($revision->toArray())->toMatchArray(['title' => 'Agents night', 'slug' => 'agents-night', 'published_at' => '2026-01-01 13:00:00']);
});

it('leaves the site its last revision while the working copy changes (3)', function (): void {
    $post = $this->publishing->create($this->posts, post());
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $edited = $this->publishing->update($this->posts, $post->id, post(['slug' => 'agents-night', 'title' => 'Changed, not published']));

    expect($edited->title)->toBe('Changed, not published')
        ->and($this->records->latestRevision($this->posts, $post->id)->content['title'])->toBe('Agents night');
});

it('republishes a published record as a new revision, keeping when it was first published', function (): void {
    $post = $this->publishing->create($this->posts, post());
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $this->clock->advance('+1 day');
    $this->publishing->update($this->posts, $post->id, post(['slug' => 'agents-night', 'title' => 'Agents night, again']));
    $again = $this->publishing->publish($this->posts, $post->id, $this->editor);

    expect($again->status)->toBe(PublishStatus::Published)
        ->and($again->publishedAt)->toBe('2026-01-01 12:00:00')
        ->and($again->contentChangedAt)->toBe('2026-01-02 12:00:00')
        ->and(array_column(array_map(static fn ($r) => $r->toArray(), $this->records->revisions($this->posts, $post->id)), 'title'))
        ->toBe(['Agents night, again', 'Agents night']);
});

it('retires a published record and brings it back, keeping its first publish (2, 11)', function (): void {
    $post = $this->publishing->create($this->posts, post());
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $this->clock->advance('+1 day');
    $retired = $this->publishing->retire($this->posts, $post->id);
    $this->clock->advance('+1 day');
    $back = $this->publishing->publish($this->posts, $post->id, $this->editor);

    expect($retired->status)->toBe(PublishStatus::Retired)
        ->and($retired->retiredAt)->toBe('2026-01-02 12:00:00')
        ->and($back->status)->toBe(PublishStatus::Published)
        ->and($back->retiredAt)->toBeNull()
        ->and($back->publishedAt)->toBe('2026-01-01 12:00:00');
});

it('refuses to retire a draft or a retired record (2)', function (): void {
    $draft = $this->publishing->create($this->posts, post());

    expect(fn () => $this->publishing->retire($this->posts, $draft->id))
        ->toThrow(IllegalTransition::class, "A record cannot go from 'draft' to 'retired'.");

    $this->publishing->publish($this->posts, $draft->id, $this->editor);
    $this->publishing->retire($this->posts, $draft->id);

    expect(fn () => $this->publishing->retire($this->posts, $draft->id))
        ->toThrow(IllegalTransition::class, "A record cannot go from 'retired' to 'retired'.");
});

it('restores a revision as a new publish, keeping the current slug (5)', function (): void {
    $post = $this->publishing->create($this->posts, post(['title' => 'Version one']));
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $one = $this->records->latestRevision($this->posts, $post->id);
    $this->publishing->update($this->posts, $post->id, post(['slug' => 'moved', 'title' => 'Version two']));
    $this->publishing->publish($this->posts, $post->id, $this->editor);

    $restored = $this->publishing->restore($this->posts, $post->id, $one->id, $this->editor);
    $revisions = $this->records->revisions($this->posts, $post->id);

    expect($restored->title)->toBe('Version one')
        ->and($restored->slug)->toBe('moved')
        ->and(count($revisions))->toBe(3)
        ->and($revisions[0]->content['title'])->toBe('Version one')
        ->and($revisions[0]->content['slug'])->toBe('moved');
});

it('restores only a revision of the same record (5, 13)', function (): void {
    $mine = $this->publishing->create($this->posts, post());
    $theirs = $this->publishing->create($this->posts, post());
    $this->publishing->publish($this->posts, $theirs->id, $this->editor);
    $theirRevision = $this->records->latestRevision($this->posts, $theirs->id);

    expect(fn () => $this->publishing->restore($this->posts, $mine->id, $theirRevision->id, $this->editor))
        ->toThrow(ContentNotFound::class, 'There is no such revision of this record.')
        ->and($this->records->findRevision($this->posts, $theirs->id, $theirRevision->id)->id)->toBe($theirRevision->id);
});

it('answers not found for a record that does not exist (13)', function (): void {
    expect(fn () => $this->publishing->publish($this->posts, 999_999, $this->editor))
        ->toThrow(ContentNotFound::class, 'There is no post with that id.')
        ->and(fn () => $this->publishing->update($this->posts, 999_999, post(['slug' => 'x'])))
        ->toThrow(ContentNotFound::class);
});

it('lists working copies by status, the most recently changed first', function (): void {
    $older = $this->publishing->create($this->posts, post(['title' => 'Older']));
    $this->clock->advance('+1 minute');
    $newer = $this->publishing->create($this->posts, post(['title' => 'Newer']));
    $this->publishing->publish($this->posts, $older->id, $this->editor);

    $titles = static fn (array $records): array => array_map(static fn ($r): string => $r->title, $records);

    expect(array_slice($titles($this->records->all($this->posts)), 0, 2))->toBe(['Newer', 'Older'])
        ->and($titles($this->records->all($this->posts, PublishStatus::Published)))->toContain('Older')
        ->and($titles($this->records->all($this->posts, PublishStatus::Published)))->not->toContain('Newer');
});

it('turns a slug taken by a racing request into a refusal, and passes other database errors on (6)', function (): void {
    $taken = $this->publishing->create($this->posts, post(['slug' => 'raced']));
    $race = new ReflectionMethod(Publishing::class, 'withSlugRace');
    $duplicate = new PDOException('duplicate');
    $duplicate->errorInfo = ['23000', 1062, 'Duplicate entry'];
    $other = new PDOException('gone away');
    $other->errorInfo = ['HY000', 2006, 'gone away'];

    expect(fn () => $race->invoke($this->publishing, 'raced', fn () => throw $duplicate))
        ->toThrow(InvalidContent::class, 'The slug “raced” is taken')
        ->and(fn () => $race->invoke($this->publishing, 'x', fn () => throw $other))
        ->toThrow(PDOException::class, 'gone away')
        ->and($taken->slug)->toBe('raced');
});

/** @return list<array<string,list<string>>> the changes of every notice queued */
function notices(FakeQueue $queue): array
{
    return array_map(static fn (array $queued): array => $queued['job']->changes, $queue->enqueued);
}

it('queues a change notice for each publish, a minute out, coalesced by one key (19)', function (): void {
    $post = $this->publishing->create($this->posts, post());

    expect($this->queue->enqueued)->toBe([]);

    $this->publishing->publish($this->posts, $post->id, $this->editor);

    expect(notices($this->queue))->toBe([['posts' => ['agents-night']]])
        ->and($this->queue->enqueued[0]['key'])->toBe('site-change-notice')
        ->and($this->queue->enqueued[0]['run_after']->format('Y-m-d H:i:s'))->toBe('2026-01-01 12:01:00');
});

it('names the old slug too when a publish moves the page (19)', function (): void {
    $post = $this->publishing->create($this->posts, post(['slug' => 'before']));
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $this->publishing->update($this->posts, $post->id, post(['slug' => 'after']));
    $this->publishing->publish($this->posts, $post->id, $this->editor);

    expect(notices($this->queue)[1])->toBe(['posts' => ['after', 'before']]);
});

it('names every slug a retired record had, and nothing for a draft saved (19)', function (): void {
    $post = $this->publishing->create($this->posts, post(['slug' => 'one']));
    $this->publishing->publish($this->posts, $post->id, $this->editor);
    $this->publishing->update($this->posts, $post->id, post(['slug' => 'two']));
    $this->publishing->retire($this->posts, $post->id);

    expect(notices($this->queue))->toBe([['posts' => ['one']], ['posts' => ['one', 'two']]]);
});

it('queues no notice when a publish is refused (19)', function (): void {
    $draft = $this->publishing->create($this->posts, ['title' => 'Bare']);

    expect(fn () => $this->publishing->publish($this->posts, $draft->id, $this->editor))->toThrow(InvalidContent::class)
        ->and($this->queue->enqueued)->toBe([]);
});
