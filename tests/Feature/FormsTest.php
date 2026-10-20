<?php

declare(strict_types=1);

use App\Libraries\Forms\Sites;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Tests\TestCase;

/*
 * Forms through the router (docs/forms.md): a site's server posting with its
 * key, as JSON and as a browser without script would; the inbox; the sites;
 * the nightly prune. The numbers are the failure list's.
 */

beforeEach(function (): void {
    $sites = TestCase::container()->get(Sites::class);
    [, $this->key] = $sites->issueKey($sites->create('forms-site', 'Forms site', 'https://forms.example')->id);
});

/** A post from the site's server: its key, the visitor's address forwarded. */
function fromSite(object $test, array $fields, ?string $key, string $as = 'json', string $form = 'contact'): ResponseInterface
{
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('POST', "http://localhost/api/site/forms/{$form}", ['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeader('X-Forwarded-For', '203.0.113.50');

    if ($key !== null) {
        $request = $request->withHeader('X-Site-Key', $key);
    }

    $request = $as === 'json'
        ? $request->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($fields, JSON_THROW_ON_ERROR)))
        : $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withBody((new StreamFactory())->createStream(http_build_query($fields)));

    return TestCase::app()->handle($request);
}

function contactFields(array $overrides = []): array
{
    return [...['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello.'], ...$overrides];
}

it('receives a JSON submission with the site key, and answers with the thanks (1, 6)', function (): void {
    $response = fromSite($this, contactFields(), $this->key);

    expect($response->getStatusCode())->toBe(200)
        ->and($this->json($response)['data']['message'])->toBe('Thanks, we have your message and will reply soon.');
});

it('refuses a submission without a key, with a wrong one, or from a disabled site (1)', function (): void {
    $this->asOwner();
    $id = $this->json($this->get('/api/sites'))['data']['sites'][0]['id'];
    $this->post("/api/sites/{$id}/disable");

    foreach ([null, 'local-site-key', 'wsk_' . str_repeat('A', 43), $this->key] as $key) {
        expect(fromSite($this, contactFields(), $key)->getStatusCode())->toBe(401);
    }
});

it('names each field it refuses, as JSON (6)', function (): void {
    $response = fromSite($this, contactFields(['email' => 'nope']), $this->key);

    expect($response->getStatusCode())->toBe(422)
        ->and($this->json($response))->toBe(['success' => false, 'error' => 'Please check the form.', 'fields' => ['email' => 'Please give an email address we can reply to.']]);
});

it('takes a form-encoded post from a browser without script, and sends it to a path on the site (8, 9)', function (): void {
    $sent = fromSite($this, contactFields(['redirect' => '/contact/thanks/']), $this->key, 'form');
    $refused = fromSite($this, contactFields(['email' => 'nope', 'back' => '/contact/']), $this->key, 'form');
    $away = fromSite($this, contactFields(['redirect' => 'https://evil.example/']), $this->key, 'form');

    expect($sent->getStatusCode())->toBe(303)
        ->and($sent->getHeaderLine('Location'))->toBe('/contact/thanks/')
        ->and($refused->getHeaderLine('Location'))->toBe('/contact/')
        ->and($away->getHeaderLine('Location'))->toBe('/');
});

it('answers an unknown form 404, and too many tries 429 (4, 7)', function (): void {
    expect(fromSite($this, contactFields(), $this->key, 'json', 'quote')->getStatusCode())->toBe(404)
        ->and(fromSite($this, contactFields(), $this->key, 'form', 'quote')->getStatusCode())->toBe(303);

    foreach (range(1, 5) as $n) {
        fromSite($this, contactFields(), $this->key);
    }

    expect(fromSite($this, contactFields(), $this->key)->getStatusCode())->toBe(429)
        ->and(fromSite($this, contactFields(), $this->key, 'form')->getStatusCode())->toBe(303);
});

it('shows the inbox, and marks a submission handled or spam once (12)', function (): void {
    fromSite($this, contactFields(['name' => 'First']), $this->key);
    fromSite($this, contactFields(['name' => 'Second']), $this->key);
    $this->asAdministrator();

    $inbox = $this->json($this->get('/api/forms/submissions'))['data']['submissions'];
    $handled = $this->post("/api/forms/submissions/{$inbox[0]['id']}/handled");
    $spam = $this->post("/api/forms/submissions/{$inbox[1]['id']}/spam");

    expect(array_column(array_column($inbox, 'fields'), 'name'))->toBe(['Second', 'First'])
        ->and($this->json($handled)['data']['submission']['status'])->toBe('handled')
        ->and($this->json($spam)['data']['submission']['status'])->toBe('spam')
        ->and($this->post("/api/forms/submissions/{$inbox[1]['id']}/handled")->getStatusCode())->toBe(409)
        ->and($this->post('/api/forms/submissions/999999/spam')->getStatusCode())->toBe(404)
        ->and($this->post('/api/forms/submissions/abc/spam')->getStatusCode())->toBe(404)
        ->and(count($this->json($this->get('/api/forms/submissions', ['status' => 'handled']))['data']['submissions']))->toBe(1)
        ->and($this->get('/api/forms/submissions', ['status' => 'read'])->getStatusCode())->toBe(422);
});

it('lets an owner add a site, issue its key once, and disable and enable it (2, 13)', function (): void {
    $this->asOwner();

    $made = $this->post('/api/sites', ['slug' => 'www-site', 'name' => 'Our site', 'origin' => 'https://www.example.com']);
    $id = $this->json($made)['data']['site']['id'];
    $key = $this->json($this->post("/api/sites/{$id}/key"))['data'];
    $list = $this->json($this->get('/api/sites'))['data']['sites'];

    expect($made->getStatusCode())->toBe(201)
        ->and($key['key'])->toStartWith('wsk_')
        ->and($key['site']['has_key'])->toBeTrue()
        ->and(json_encode($list))->not->toContain($key['key'])
        ->and($this->json($this->post("/api/sites/{$id}/disable"))['data']['site']['status'])->toBe('disabled')
        ->and($this->post("/api/sites/{$id}/disable")->getStatusCode())->toBe(409)
        ->and($this->json($this->post("/api/sites/{$id}/enable"))['data']['site']['status'])->toBe('active')
        ->and($this->post('/api/sites', ['slug' => 'Bad', 'name' => 'x', 'origin' => 'https://a.example'])->getStatusCode())->toBe(422)
        ->and($this->post('/api/sites/999999/key')->getStatusCode())->toBe(404);
});

it('deletes submissions past the retention setting every night (11)', function (): void {
    fromSite($this, contactFields(), $this->key);
    TestCase::pdo()->exec("UPDATE form_submissions SET form_submissions_created_at = '2025-01-01 00:00:00'");
    fromSite($this, contactFields(), $this->key);

    $pruned = $this->withCronSecret()->json($this->get('/cron/forms'))['data'];

    expect($pruned)->toBe(['pruned' => 1, 'retention_days' => 180]);
});
