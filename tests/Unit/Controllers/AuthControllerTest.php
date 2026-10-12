<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Domain\Auth\TooManyAttempts;
use App\Libraries\Http\JsonResponder;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\Fakes\FakeIdentity;

/*
 * The paths the feature tests cannot reach through the router: throttling is
 * off in the test container, so a rate-limited sign-in is driven here with a
 * fake identity.
 */

it('answers a rate-limited sign-in with 429 and says to wait', function (): void {
    $identity = FakeIdentity::failingLoginWith(new TooManyAttempts('Too many attempts. Try again shortly.'));
    $controller = new AuthController(new JsonResponder(new ResponseFactory()), $identity);

    $request = (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/auth/login')
        ->withParsedBody(['email' => 'a@example.test', 'password' => 'x']);
    $response = $controller->login($request, (new ResponseFactory())->createResponse());
    $response->getBody()->rewind();

    expect($response->getStatusCode())->toBe(429)
        ->and(json_decode((string) $response->getBody(), true)['error'])->toBe('Too many attempts. Try again shortly.')
        ->and($identity->loginAttempts)->toBe([['a@example.test', 'x']]);
});
