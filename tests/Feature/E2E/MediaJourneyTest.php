<?php

declare(strict_types=1);

use App\Domain\Content\ChangeNotice;
use App\Domain\Ports\QueuePort;
use App\Domain\Ports\StoragePort;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\E2EArtifact;
use Tests\Support\TestImages;
use Tests\TestCase;

/*
 * An image end to end (docs/content.md, "Media"): an editor uploads a photo
 * with its metadata, the site cannot fetch it; puts it on a page, which will
 * not publish until the image is described; describes and publishes it, and
 * the site gets the image with its description and can fetch the file, with
 * none of the metadata; corrects the credit, and the page is named in a
 * change notice. Ends by writing coverage/e2e/media-journey.json.
 */

it('takes an image from upload to the site, through a corrected credit, and records what the site saw', function (): void {
    TestCase::container()->get(StoragePort::class)->files = [];
    $queue = TestCase::container()->get(QueuePort::class);
    $artifact = new E2EArtifact('media-journey');
    $site = fn (): array => array_column($this->json($this->get('/api/site/pages'))['data']['records'], null, 'slug');
    $this->asAdministrator();

    $upload = TestCase::app()->handle(
        $this->buildRequest('POST', '/api/content/media', [], ['Content-Type' => 'image/jpeg'])
            ->withBody((new StreamFactory())->createStream(TestImages::jpegWithMetadata('SAMPLE-HOME-ADDRESS')))
    );
    $id = $this->json($upload)['data']['media']['id'];
    $beforePublic = $this->get("/api/site/media/{$id}.jpg")->getStatusCode();

    $page = $this->json($this->post('/api/content/pages', [
        'title' => 'Our room', 'summary' => 'Where we meet.', 'body' => [['type' => 'image', 'media_id' => $id, 'caption' => null]],
    ]))['data']['record']['id'];
    $refused = $this->json($this->post("/api/content/pages/{$page}/publish"))['error'] ?? null;

    $this->put("/api/content/media/{$id}", ['alt' => 'Sample: a room set out for a talk', 'licence' => 'permission', 'credit_name' => 'Sample Person']);
    $this->post("/api/content/pages/{$page}/publish");
    $shown = $site()['our-room']['body'][0]['media'] ?? null;
    $file = (string) $this->get("/api/site/media/{$id}.jpg")->getBody();

    $queue->enqueued = [];
    $this->put("/api/content/media/{$id}", ['alt' => 'Sample: a room set out for a talk', 'licence' => 'permission', 'credit_name' => 'Sample Studio']);
    $corrected = $site()['our-room']['body'][0]['media']['credit_name'] ?? null;
    $notices = array_map(static fn (array $queued): array => $queued['job']->changes, array_values(array_filter(
        $queue->enqueued,
        static fn (array $queued): bool => $queued['job'] instanceof ChangeNotice,
    )));

    $artifact
        ->fact('site fetching the image before it is public', $beforePublic)
        // The id differs from run to run; the artifact must not.
        ->fact('publishing with the image undescribed', $refused === null ? null : str_replace("Image {$id} ", 'Image <id> ', $refused))
        ->fact('the image as the site gets it', $shown === null ? null : ['alt' => $shown['alt'], 'credit_name' => $shown['credit_name'], 'width' => $shown['width'], 'height' => $shown['height']])
        ->fact('credit after correcting it', $corrected)
        ->fact('change notices after correcting it', $notices)
        ->check('an image no published record uses is not served', $beforePublic === 404)
        ->check('a page with an undescribed image is not published', $refused === "This cannot be published yet. Image {$id} needs a description (alt text).")
        ->check('the site gets the image with its description and credit', ($shown['alt'] ?? null) === 'Sample: a room set out for a talk' && ($shown['credit_name'] ?? null) === 'Sample Person')
        ->check('the served file is a JPEG with none of the upload\'s metadata', str_starts_with($file, "\xFF\xD8\xFF") && !str_contains($file, 'SAMPLE-HOME-ADDRESS'))
        ->check('a corrected credit reaches the site, and the page is named for a rebuild', $corrected === 'Sample Studio' && $notices === [['pages' => ['our-room']]]);

    $written = json_decode((string) file_get_contents($artifact->write()), true);

    expect($written['passed'])->toBeTrue()
        ->and($written['sha256'])->toBe($artifact->digest());
});
