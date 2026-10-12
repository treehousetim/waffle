<?php

declare(strict_types=1);

use App\Domain\Auth\Permission;
use App\Domain\Auth\Role;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Middleware\PermissionMiddleware;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\Fakes\FakeIdentity;
use Tests\Support\RecordingHandler;

/*
 * A route needs one permission; a level without it gets 403.
 */

beforeEach(function (): void {
    $this->json = new JsonResponder(new ResponseFactory());
    $this->handler = new RecordingHandler();
    $this->request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/api/hello');
});

it('lets through a level that holds the permission', function (Role $role): void {
    $response = (new PermissionMiddleware(FakeIdentity::signedIn($role), $this->json, Permission::Home))
        ->process($this->request, $this->handler);

    expect($this->handler->wasCalled)->toBeTrue()
        ->and($response->getStatusCode())->toBe(204);
})->with([
    'owner' => [Role::Owner],
    'administrator' => [Role::Administrator],
    'user' => [Role::User],
]);

it('refuses a level without it, saying so', function (): void {
    $response = (new PermissionMiddleware(FakeIdentity::signedIn(Role::User), $this->json, Permission::SettingsView))
        ->process($this->request, $this->handler);
    $response->getBody()->rewind();

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and(json_decode((string) $response->getBody(), true)['error'])->toBe('Your access level does not include this.');
});

it('refuses a person with no level at all', function (): void {
    expect((new PermissionMiddleware(FakeIdentity::signedIn(), $this->json, Permission::Home))
        ->process($this->request, $this->handler)->getStatusCode())->toBe(403);
});
