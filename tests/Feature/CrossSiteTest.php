<?php

declare(strict_types=1);

use Tests\Support\Factory;

/*
 * The cross-site defence, through the router: SessionCookie's SameSite=Lax is
 * the first layer, SameSiteMiddleware the second (Domain/Auth/CrossSiteRequest).
 */

it('refuses a write a browser says came from another site', function (string $site): void {
    $this->asOwner();

    $response = $this->post('/api/auth/logout', [], ['Sec-Fetch-Site' => $site]);

    expect($response->getStatusCode())->toBe(403)
        ->and($this->json($response)['error'])->toBe('That request did not come from this site.')
        // Refused before it ran: still signed in.
        ->and($this->get('/api/auth/me')->getStatusCode())->toBe(200);
})->with(['cross-site', 'same-site']);

it('refuses a write whose Origin is another host, when there is no fetch metadata', function (): void {
    $response = $this->post('/api/auth/login', ['email' => 'a@b.c', 'password' => 'x'], ['Origin' => 'https://evil.example']);

    expect($response->getStatusCode())->toBe(403);
});

it('accepts a write from the same origin', function (): void {
    $id = Factory::createUser();
    [$email] = Factory::credentialsFor($id);

    $response = $this->post(
        '/api/auth/login',
        ['email' => $email, 'password' => Factory::PASSWORD],
        ['Sec-Fetch-Site' => 'same-origin', 'Origin' => 'http://localhost'],
    );

    expect($response->getStatusCode())->toBe(200);
});

it('lets a read through whatever site it came from', function (): void {
    expect($this->get('/api/auth/me', [], ['Sec-Fetch-Site' => 'cross-site'])->getStatusCode())->toBe(401);
});

it('refuses a form-encoded write to the API, which a plain HTML form could send', function (): void {
    $request = $this->buildRequest('POST', '/api/auth/login')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody(['email' => 'a@b.c', 'password' => 'x']);

    $response = Tests\TestCase::app()->handle($request);

    expect($response->getStatusCode())->toBe(415)
        ->and($this->json($response)['error'])->toBe('Only JSON is accepted here.');
});

it('refuses the same form write when the path is percent-encoded', function (): void {
    // The router decodes /%61pi to /api; the refusal has to see what it sees.
    $request = $this->buildRequest('POST', '/%61pi/auth/login')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody(['email' => 'a@b.c', 'password' => 'x']);

    $response = Tests\TestCase::app()->handle($request);

    expect($response->getStatusCode())->toBe(415)
        ->and($this->json($response)['error'])->toBe('Only JSON is accepted here.');
});

it('accepts a write from the same origin on a port that is not the default', function (): void {
    // Locally the app is on :8080. Slim builds the request's Host header from
    // the URI without its port, so the origin must be compared with the
    // request's own host and port, or every browser sign-in is refused.
    $id = Factory::createUser();
    [$email] = Factory::credentialsFor($id);

    $response = $this->app()->handle(onPort(8080, 'http://localhost:8080', $email));

    expect($response->getStatusCode())->toBe(200);
});

it('refuses a write whose Origin names the same host on another port', function (): void {
    $response = $this->app()->handle(onPort(8080, 'http://localhost:9999', 'a@b.c'));

    expect($response->getStatusCode())->toBe(403);
});

/** A sign-in to http://localhost:<port>, from $origin, with no fetch metadata. */
function onPort(int $port, string $origin, string $email): Psr\Http\Message\ServerRequestInterface
{
    return (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('POST', "http://localhost:{$port}/api/auth/login", ['REMOTE_ADDR' => '127.0.0.1'])
        ->withHeader('Origin', $origin)
        ->withHeader('Content-Type', 'application/json')
        ->withBody((new Slim\Psr7\Factory\StreamFactory())->createStream(json_encode([
            'email' => $email,
            'password' => Tests\Support\Factory::PASSWORD,
        ], JSON_THROW_ON_ERROR)));
}
