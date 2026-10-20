<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Domain\Content\RecordTypes;
use App\Domain\Queue\QueueSettings;
use App\Libraries\Content\ChangeNoticeHandler;
use App\Libraries\Queue\DatabaseQueue;
use App\Libraries\Queue\JobRegistry;
use App\Libraries\Content\Publishing;
use App\Models\Content\RecordRepository;
use App\Models\TypeRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeSiteNotifier;
use Tests\Support\QueueFixtures;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fakes\FrozenClock;
use Tests\TestCase;

/*
 * Publishing against real commits (docs/content.md, 4., 14.). Not
 * transaction-wrapped, so everything made here is deleted here.
 */

beforeEach(function (): void {
    $this->records = TestCase::container()->get(RecordRepository::class);
    $this->publishing = new Publishing($this->records, new FrozenClock(), noticeQueue());
    $this->posts = TestCase::container()->get(RecordTypes::class)->find('posts');
    $this->editor = Factory::createUser(Role::Administrator, 'schema-content@example.test');
});

afterEach(function (): void {
    $pdo = TestCase::pdo();

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $pdo->exec("DELETE FROM posts WHERE posts_slug LIKE 'schema-%'");
    $pdo->exec("DELETE FROM jobs WHERE jobs_type_id = (SELECT types_id FROM types WHERE types_group = 'job' AND types_name = 'site-change-notice')");
    $pdo->exec("DELETE FROM users WHERE email = 'schema-content@example.test'");
});

/** The real queue, knowing only the change notice. */
function noticeQueue(): DatabaseQueue
{
    $registry = new JobRegistry(new DI\Container(['job:site-change-notice' => new ChangeNoticeHandler(new FakeSiteNotifier())]), ['site-change-notice']);

    return new DatabaseQueue(QueueFixtures::repository(), $registry, new FrozenClock(), new QueueSettings());
}

function noticeCount(): int
{
    return (int) TestCase::pdo()->query("SELECT COUNT(*) FROM jobs JOIN types ON types_id = jobs_type_id WHERE types_name = 'site-change-notice'")->fetchColumn();
}

function contentConnection(): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], (int) $_ENV['DB_PORT'], $_ENV['DB_NAME']),
        (string) $_ENV['DB_USER'],
        (string) $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
}

it('commits a publish and its revision together, or neither (4)', function (): void {
    $post = $this->publishing->create($this->posts, ['title' => 'Schema', 'slug' => 'schema-one', 'summary' => 'S', 'body' => 'B']);

    expect(fn () => $this->records->transactional(function () use ($post): void {
        $this->publishing->publish($this->posts, $post->id, $this->editor);

        throw new RuntimeException('half way');
    }))->toThrow(RuntimeException::class, 'half way');

    expect($this->records->find($this->posts, $post->id)->status->value)->toBe('draft')
        ->and($this->records->revisions($this->posts, $post->id))->toBe([])
        ->and(noticeCount())->toBe(0)
        ->and(TestCase::pdo()->inTransaction())->toBeFalse();

    $this->publishing->publish($this->posts, $post->id, $this->editor);

    expect($this->records->find($this->posts, $post->id)->status->value)->toBe('published')
        ->and($this->records->revisions($this->posts, $post->id))->toHaveCount(1)
        ->and(noticeCount())->toBe(1);
});

it('makes a second publish of the same record wait for the first (14)', function (): void {
    $post = $this->publishing->create($this->posts, ['title' => 'Schema', 'slug' => 'schema-two', 'summary' => 'S', 'body' => 'B']);
    $other = contentConnection();
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $otherPublishing = new Publishing(new RecordRepository($other, TestCase::container()->get(TypeRepository::class)), new FrozenClock(), new FakeQueue());

    // The first publish holds the record's row lock in an open transaction.
    TestCase::pdo()->beginTransaction();
    $this->publishing->publish($this->posts, $post->id, $this->editor);

    expect(fn () => $otherPublishing->publish($this->posts, $post->id, $this->editor))
        ->toThrow(PDOException::class, 'Lock wait timeout');

    TestCase::pdo()->commit();
    $otherPublishing->publish($this->posts, $post->id, $this->editor);

    expect($this->records->revisions($this->posts, $post->id))->toHaveCount(2);
});
