<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Middleware\AuthMiddleware;
use App\Models\UserRepository;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeIdentity;
use Tests\Support\RecordingHandler;
use Tests\TestCase;

beforeEach(function (): void {
    $this->json = new JsonResponder(new ResponseFactory());
    $this->handler = new RecordingHandler();
    $this->request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/hello');
    $this->users = new UserRepository(TestCase::pdo());
});

it('lets a signed-in request through untouched', function (): void {
    // A real row, because the middleware reads the account's status on every
    // request: an account turned off has to stop working in the tab it already
    // has open, not just at the next sign-in.
    $id = Factory::createUser(Role::Owner);
    $identity = FakeIdentity::signedIn(Role::Owner, $id);

    $response = (new AuthMiddleware($identity, $this->json, $this->users))->process($this->request, $this->handler);

    expect($this->handler->wasCalled)->toBeTrue()
        ->and($response->getStatusCode())->toBe(204)
        ->and($this->handler->receivedRequest)->toBe($this->request)
        ->and($identity->loggedOut)->toBeFalse();
});

it('rejects an anonymous request with a 401 and never calls the handler', function (): void {
    $response = (new AuthMiddleware(FakeIdentity::anonymous(), $this->json, $this->users))
        ->process($this->request, $this->handler);
    $response->getBody()->rewind();

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(401)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and(json_decode((string) $response->getBody(), true))
        ->toBe(['success' => false, 'error' => 'Authentication required.']);
});

it('signs out an account turned off, and refuses with 403', function (): void {
    $id = Factory::createDisabledUser();
    $identity = FakeIdentity::signedIn(Role::User, $id);

    $response = (new AuthMiddleware($identity, $this->json, $this->users))->process($this->request, $this->handler);

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and($identity->loggedOut)->toBeTrue();
});

it('signs out a session whose account no longer exists', function (): void {
    $identity = FakeIdentity::signedIn(Role::User, 999_999_999);

    $response = (new AuthMiddleware($identity, $this->json, $this->users))->process($this->request, $this->handler);

    expect($response->getStatusCode())->toBe(403)
        ->and($identity->loggedOut)->toBeTrue();
});
