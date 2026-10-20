<?php

declare(strict_types=1);

use App\Controllers\ContentController;
use App\Controllers\SiteContentController;
use App\Domain\Content\RecordFields;
use App\Domain\Content\RecordType;
use App\Domain\Content\RecordTypes;
use App\Domain\Content\ChangeNotice;
use App\Domain\Ports\QueuePort;
use App\Domain\Ports\StoragePort;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\TestImages;
use Tests\TestCase;

/*
 * The media library through the router (docs/content.md, "Media"): upload,
 * describe, use in a page, publish, and the site fetching it. The numbers
 * are the failure list's.
 */

beforeEach(function (): void {
    TestCase::container()->get(StoragePort::class)->files = [];
    // An upload: the image itself as the body.
    $this->upload = fn (string $bytes, string $type = 'image/jpeg'): ResponseInterface => TestCase::app()->handle(
        $this->buildRequest('POST', '/api/content/media', [], ['Content-Type' => $type])->withBody((new StreamFactory())->createStream($bytes))
    );
});

it('uploads an image as a re-encoded JPEG, once however often it is sent (23, 25)', function (): void {
    $this->asAdministrator();

    $first = ($this->upload)(TestImages::jpegWithMetadata('HOME-ADDRESS'));
    $again = ($this->upload)(TestImages::jpegWithMetadata('HOME-ADDRESS'));
    $id = $this->json($first)['data']['media']['id'];
    $file = (string) $this->get("/api/content/media/{$id}/file")->getBody();

    expect($first->getStatusCode())->toBe(201)
        ->and($this->json($again)['data']['media']['id'])->toBe($id)
        ->and($this->json($first)['data']['media'])->toMatchArray(['alt' => '', 'licence' => 'own', 'public' => false, 'problems' => ["Image {$id} needs a description (alt text)."]])
        ->and($file)->not->toContain('HOME-ADDRESS')
        ->and(str_starts_with($file, "\xFF\xD8\xFF"))->toBeTrue()
        ->and(count($this->json($this->get('/api/content/media'))['data']['media']))->toBe(1)
        ->and(($this->upload)('not an image')->getStatusCode())->toBe(422);
});

it('keeps a page with an undescribed image from being published, and makes the image public once it is (24, 26)', function (): void {
    $this->asAdministrator();
    $id = $this->json(($this->upload)(TestImages::jpeg(1200, 800)))['data']['media']['id'];
    $page = $this->json($this->post('/api/content/pages', [
        'title' => 'Gallery night', 'summary' => 'Photos.', 'body' => [['type' => 'image', 'media_id' => $id, 'caption' => 'The room']],
    ]))['data']['record']['id'];

    $refused = $this->post("/api/content/pages/{$page}/publish");
    $beforePublic = $this->get("/api/site/media/{$id}.jpg")->getStatusCode();
    $this->put("/api/content/media/{$id}", ['alt' => 'A room of people at laptops', 'licence' => 'own']);
    $published = $this->post("/api/content/pages/{$page}/publish");
    $site = array_column($this->json($this->get('/api/site/pages'))['data']['records'], null, 'slug')['gallery-night'];
    $file = $this->get("/api/site/media/{$id}.jpg");

    expect($refused->getStatusCode())->toBe(422)
        ->and($this->json($refused)['error'])->toBe("This cannot be published yet. Image {$id} needs a description (alt text).")
        ->and($beforePublic)->toBe(404)
        ->and($published->getStatusCode())->toBe(200)
        ->and($site['body'][0]['media'])->toBe([
            'id' => $id, 'path' => "/api/site/media/{$id}.jpg", 'width' => 1200, 'height' => 800,
            'alt' => 'A room of people at laptops', 'caption' => null, 'credit_name' => null, 'credit_url' => null,
        ])
        ->and($file->getStatusCode())->toBe(200)
        ->and($file->getHeaderLine('Content-Type'))->toBe('image/jpeg')
        ->and($file->getHeaderLine('Set-Cookie'))->toBe('');
});

it('refuses a page naming an image that is not in the library, and serves a gallery\'s images (22)', function (): void {
    $this->asAdministrator();
    $a = $this->json(($this->upload)(TestImages::jpeg(400, 300)))['data']['media']['id'];
    $b = $this->json(($this->upload)(TestImages::png(300, 300), 'image/png'))['data']['media']['id'];

    foreach ([$a, $b] as $id) {
        $this->put("/api/content/media/{$id}", ['alt' => "Image {$id}"]);
    }

    $missing = $this->post('/api/content/pages', ['title' => 'Missing', 'body' => [['type' => 'image', 'media_id' => 999999]]]);
    $page = $this->json($this->post('/api/content/pages', ['title' => 'Two', 'summary' => 'S', 'body' => [['type' => 'gallery', 'media_ids' => [$a, $b]]]]))['data']['record']['id'];
    $this->post("/api/content/pages/{$page}/publish");
    $site = array_column($this->json($this->get('/api/site/pages'))['data']['records'], null, 'slug')['two'];

    expect($missing->getStatusCode())->toBe(422)
        ->and($this->json($missing)['error'])->toBe('There is no image 999999 in the media library.')
        ->and(array_column($site['body'][0]['media'], 'id'))->toBe([$a, $b]);
});

it('describes an image, refusing bad details, and answers 404 for none (24)', function (): void {
    $this->asAdministrator();
    $id = $this->json(($this->upload)(TestImages::jpeg()))['data']['media']['id'];

    $credited = $this->put("/api/content/media/{$id}", ['alt' => 'A cat', 'licence' => 'licensed']);

    expect($this->json($credited)['data']['media']['problems'])->toBe(["Image {$id} needs a credit."])
        ->and($this->put("/api/content/media/{$id}", ['licence' => 'stolen'])->getStatusCode())->toBe(422)
        ->and($this->put('/api/content/media/999999', ['alt' => 'x'])->getStatusCode())->toBe(404)
        ->and($this->get('/api/content/media/999999/file')->getStatusCode())->toBe(404)
        ->and($this->get('/api/site/media/999999.jpg')->getStatusCode())->toBe(404);
});

it('checks, publishes and hands the site an image a record type\'s own field names (22, 24, 26)', function (): void {
    $this->asAdministrator();
    $id = $this->json(($this->upload)(TestImages::jpeg(1200, 630)))['data']['media']['id'];
    // A stand-in for a project's own field naming an image, such as an event's
    // cover: posts whose fields name one image, with no column of their own.
    $withCover = fn (int $cover): RecordTypes => new RecordTypes(new RecordType('posts', 'post', 'post', new class ($cover) implements RecordFields {
        public function __construct(private int $cover)
        {
        }

        public function names(): array
        {
            return [];
        }

        public function parse(array $input): array
        {
            return [];
        }

        public function present(array $stored): array
        {
            return [];
        }

        public function publishProblems(array $stored): array
        {
            return [];
        }

        public function images(array $stored): array
        {
            return ['cover' => $this->cover];
        }
    }), TestCase::container()->get('record_type:pages'));
    $use = function (RecordTypes $types): void {
        $this->swap(RecordTypes::class, $types);
        $this->swap(ContentController::class, TestCase::container()->make(ContentController::class));
        $this->swap(SiteContentController::class, TestCase::container()->make(SiteContentController::class));
    };
    $post = ['title' => 'With a cover', 'summary' => 'S', 'body' => 'Text.'];

    $use($withCover(999999));
    $missing = $this->post('/api/content/posts', $post);
    $use($withCover($id));
    $record = $this->json($this->post('/api/content/posts', $post))['data']['record']['id'];
    $refused = $this->post("/api/content/posts/{$record}/publish");
    $this->put("/api/content/media/{$id}", ['alt' => 'Sample: a cover', 'licence' => 'own']);
    $this->post("/api/content/posts/{$record}/publish");
    $site = array_column($this->json($this->get('/api/site/posts'))['data']['records'], null, 'slug')['with-a-cover'];

    expect($this->json($missing)['error'])->toBe('There is no image 999999 in the media library.')
        ->and($this->json($refused)['error'])->toBe("This cannot be published yet. Image {$id} needs a description (alt text).")
        ->and($site['cover'])->toMatchArray(['id' => $id, 'path' => "/api/site/media/{$id}.jpg", 'alt' => 'Sample: a cover'])
        ->and($this->get("/api/site/media/{$id}.jpg")->getStatusCode())->toBe(200);
});

it('tells the site when a public image\'s description changes, naming the published pages that show it (28)', function (): void {
    $this->asAdministrator();
    $queue = TestCase::container()->get(QueuePort::class);
    $notices = static fn (): array => array_map(static fn (array $queued): array => $queued['job']->changes, array_values(array_filter(
        $queue->enqueued,
        static fn (array $queued): bool => $queued['job'] instanceof ChangeNotice,
    )));
    $shown = $this->json(($this->upload)(TestImages::jpeg(640, 480)))['data']['media']['id'];
    $unused = $this->json(($this->upload)(TestImages::jpeg(320, 240)))['data']['media']['id'];
    $this->put("/api/content/media/{$shown}", ['alt' => 'Sample: first words']);
    $page = $this->json($this->post('/api/content/pages', ['title' => 'Shows it', 'summary' => 'S', 'body' => [['type' => 'image', 'media_id' => $shown]]]))['data']['record']['id'];
    $this->post('/api/content/pages', ['title' => 'Draft with it', 'summary' => 'S', 'body' => [['type' => 'image', 'media_id' => $shown]]]);
    $this->post("/api/content/pages/{$page}/publish");
    $queue->enqueued = [];

    $this->put("/api/content/media/{$unused}", ['alt' => 'Sample: never shown']);
    $afterUnused = $notices();
    $described = $this->put("/api/content/media/{$shown}", ['alt' => 'Sample: better words', 'licence' => 'licensed', 'credit_name' => 'Sample Person']);

    expect($afterUnused)->toBe([])
        ->and($described->getStatusCode())->toBe(200)
        ->and($notices())->toBe([['pages' => ['shows-it']]])
        ->and(array_column($this->json($this->get('/api/site/pages'))['data']['records'], null, 'slug')['shows-it']['body'][0]['media']['alt'])->toBe('Sample: better words');
});
