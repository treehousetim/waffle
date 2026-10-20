<?php

declare(strict_types=1);

use App\Libraries\Forms\Sites;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\E2EArtifact;
use Tests\TestCase;

/*
 * Getting in touch and getting in, end to end (docs/forms.md,
 * docs/invitations.md): a visitor writes through the site's contact form, an
 * organiser reads it in the inbox, invites the writer, and the writer joins
 * from the link and is signed in. Ends by writing
 * coverage/e2e/joining-journey.json.
 */

it('takes a visitor from the contact form to a signed-in account, and records what it saw', function (): void {
    $artifact = new E2EArtifact('joining-journey');
    $sites = TestCase::container()->get(Sites::class);
    [, $key] = $sites->issueKey($sites->create('journey-site', 'Journey site', 'https://journey.example')->id);

    $sent = TestCase::app()->handle((new ServerRequestFactory())
        ->createServerRequest('POST', 'http://localhost/api/site/forms/contact', ['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeader('X-Site-Key', $key)
        ->withHeader('X-Forwarded-For', '203.0.113.60')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withBody((new StreamFactory())->createStream(http_build_query([
            'name' => 'Grace', 'email' => 'grace@example.test', 'message' => 'Can I come along?', 'redirect' => '/contact/thanks/',
        ]))));

    $this->asOwner();
    $inbox = $this->json($this->get('/api/forms/submissions'))['data']['submissions'];
    $email = $inbox[0]['fields']['email'] ?? null;
    $token = $this->json($this->post('/api/invitations', ['email' => $email, 'level' => 'user']))['data']['token'];
    $this->post("/api/forms/submissions/{$inbox[0]['id']}/handled");
    $this->post('/api/auth/logout');

    $accepted = $this->post("/api/invite/{$token}/accept", ['name' => 'Grace Hopper', 'password' => 'correct horse battery'])->getStatusCode();
    $me = $this->json($this->get('/api/auth/me'))['data'];

    $artifact
        ->fact('the browser was sent to', $sent->getHeaderLine('Location'))
        ->fact('the inbox showed', $inbox[0]['fields'] ?? null)
        ->fact('signed in as', ['email' => $me['email'] ?? null, 'role' => $me['role'] ?? null])
        ->check('the site\'s form post reached the inbox', $sent->getStatusCode() === 303 && $email === 'grace@example.test')
        ->check('the browser went back to the site\'s thanks page', $sent->getHeaderLine('Location') === '/contact/thanks/')
        ->check('the invited writer joined and is signed in as a user', $accepted === 200 && ($me['email'] ?? null) === 'grace@example.test' && ($me['role'] ?? null) === 'user');

    $written = json_decode((string) file_get_contents($artifact->write()), true);

    expect($written['passed'])->toBeTrue()
        ->and($written['sha256'])->toBe($artifact->digest());
});
