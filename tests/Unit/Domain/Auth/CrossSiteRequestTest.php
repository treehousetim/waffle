<?php

declare(strict_types=1);

use App\Domain\Auth\CrossSiteRequest;
use App\Domain\Auth\SessionCookie;

/*
 * Cross-site defence.
 *
 * Form-encoded bodies are parsed, so without one a cross-site form post is a
 * *simple* request with no preflight to stop it. Chrome's Lax-by-default
 * blunts that, which is a browser policy rather than a control this
 * application asserts.
 */

it('refuses a write from any other origin, the same site included', function (): void {
    // Sec-Fetch-Site is set by the browser and cannot be set by page script,
    // which is what makes it worth more than an Origin comparison.
    expect(CrossSiteRequest::isForbidden('POST', 'cross-site', null, 'waffle.example.com'))->toBeTrue()
        ->and(CrossSiteRequest::isForbidden('POST', 'same-origin', null, 'waffle.example.com'))->toBeFalse()
        /*
         * Same-site is refused: any other subdomain of example.com is the
         * same *site* as waffle.example.com, and whatever runs there could post
         * a form here with the visitor's session.
         */
        ->and(CrossSiteRequest::isForbidden('POST', 'same-site', null, 'waffle.example.com'))->toBeTrue()
        // `none` is a typed address or a bookmark, which is a person.
        ->and(CrossSiteRequest::isForbidden('POST', 'none', null, 'waffle.example.com'))->toBeFalse();
});

it('leaves safe methods alone', function (): void {
    /*
     * A GET changes nothing, and refusing cross-site reads would break an
     * ordinary link. Every method that can change something is covered; the
     * three that should not are not.
     */
    foreach (['GET', 'HEAD', 'OPTIONS', 'get'] as $method) {
        expect(CrossSiteRequest::isForbidden($method, 'cross-site', 'https://evil.example', 'waffle.example.com'))
            ->toBeFalse();
    }

    foreach (['POST', 'PUT', 'DELETE', 'patch'] as $method) {
        expect(CrossSiteRequest::isForbidden($method, 'cross-site', null, 'waffle.example.com'))->toBeTrue();
    }
});

it('falls back to comparing the origin against the host', function (): void {
    // For a browser that sends no fetch metadata. Compared against the
    // request's own Host, so there is nothing to configure per environment.
    expect(CrossSiteRequest::isForbidden('POST', null, 'https://evil.example', 'waffle.example.com'))->toBeTrue()
        ->and(CrossSiteRequest::isForbidden('POST', null, 'https://waffle.example.com', 'waffle.example.com'))->toBeFalse()
        // Scheme is not compared: HSTS and the redirect handle that, and a
        // mismatch here would refuse a local dev origin on plain HTTP.
        ->and(CrossSiteRequest::isForbidden('POST', null, 'http://waffle.example.com', 'waffle.example.com'))->toBeFalse();
});

it('treats the port as part of the origin', function (): void {
    // Host carries the port, and two ports are two origins. Local development
    // is the case that makes this concrete: 8085 is not 8080.
    expect(CrossSiteRequest::isForbidden('POST', null, 'http://localhost:8085', 'localhost:8085'))->toBeFalse()
        ->and(CrossSiteRequest::isForbidden('POST', null, 'http://localhost:8080', 'localhost:8085'))->toBeTrue();
});

it('allows a request with neither header, because that is not a browser', function (): void {
    /*
     * curl, the cron jobs, an API client. They send no Origin and carry no
     * cookie, so refusing them would break every non-browser caller to defend
     * a cookie none of them use. This is a defence for the cookie-authenticated
     * frontend, not an authentication scheme -- which is why it is one of two
     * layers.
     */
    expect(CrossSiteRequest::isForbidden('POST', null, null, 'waffle.example.com'))->toBeFalse()
        ->and(CrossSiteRequest::isForbidden('POST', '', '', 'waffle.example.com'))->toBeFalse()
        // A literal "null" origin is what a sandboxed iframe or a redirected
        // form sends. It names no site to compare, so the fetch-metadata
        // check above is what has to catch it -- refusing here would break
        // ordinary redirects.
        ->and(CrossSiteRequest::isForbidden('POST', null, 'null', 'waffle.example.com'))->toBeFalse();
});

it('refuses an origin it cannot parse', function (): void {
    // An unparseable origin yields no host, and no host cannot match a real
    // one. Failing closed is the only safe direction for a value an attacker
    // chooses.
    expect(CrossSiteRequest::isForbidden('POST', null, 'not a url', 'waffle.example.com'))->toBeTrue();
});

it('gives the session cookie every attribute it was missing', function (): void {
    $params = SessionCookie::params(true);

    expect($params['httponly'])->toBeTrue()
        ->and($params['samesite'])->toBe('Lax')
        ->and($params['secure'])->toBeTrue()
        // Host-only: a cookie offered to subdomains would hand the session to
        // whatever else runs on the domain.
        ->and($params['domain'])->toBe('')
        ->and($params['path'])->toBe('/')
        ->and($params['lifetime'])->toBe(0);
});

it('names the session cookie for the project, so two apps on one host keep their own sessions', function (): void {
    // Cookies ignore the port: two apps on localhost:8080 and :8091 under
    // PHP's PHPSESSID share one cookie and sign each other out.
    $index = (string) file_get_contents(dirname(__DIR__, 4) . '/src/public/index.php');

    expect(SessionCookie::NAME)->toMatch('/^[a-z][a-z0-9_]*_session$/')
        ->and($index)->toContain('session_name(App\\Domain\\Auth\\SessionCookie::NAME);');
});

it('leaves Secure off when the request is not over TLS', function (): void {
    /*
     * Local development is plain HTTP on a loopback port, and a Secure cookie
     * there is never sent back -- a login that appears to succeed and then
     * does nothing. Production Caddy passes `HTTPS=on` over TLS, which is
     * the signal this reads.
     */
    expect(SessionCookie::params(false)['secure'])->toBeFalse()
        ->and(SessionCookie::overHttps(['HTTPS' => 'on']))->toBeTrue()
        ->and(SessionCookie::overHttps(['HTTPS' => 'off']))->toBeFalse()
        ->and(SessionCookie::overHttps([]))->toBeFalse()
        ->and(SessionCookie::overHttps(['HTTP_X_FORWARDED_PROTO' => 'https']))->toBeTrue()
        ->and(SessionCookie::overHttps(['HTTP_X_FORWARDED_PROTO' => 'http']))->toBeFalse()
        // Not a string, which is what a malformed CGI environment looks like.
        ->and(SessionCookie::overHttps(['HTTPS' => 1]))->toBeFalse();
});

it('refuses on /api the bodies a plain HTML form can send, so every write needs a preflight', function (): void {
    foreach (['application/x-www-form-urlencoded', 'multipart/form-data; boundary=x', 'text/plain;charset=UTF-8'] as $type) {
        expect(CrossSiteRequest::isFormBody('POST', '/api/settings', $type))->toBeTrue($type);
    }

    expect(CrossSiteRequest::isFormBody('POST', '/api/settings', 'application/json'))->toBeFalse()
        ->and(CrossSiteRequest::isFormBody('POST', '/api/auth/logout', ''))->toBeFalse()
        ->and(CrossSiteRequest::isFormBody('GET', '/api/settings', 'text/plain'))->toBeFalse()
        // Many webhook senders post form-encoded, server to server.
        ->and(CrossSiteRequest::isFormBody('POST', '/webhooks/example', 'application/x-www-form-urlencoded'))->toBeFalse();
});

it('decodes the path before deciding it is outside /api, as the router does', function (): void {
    /*
     * Slim percent-decodes the path before routing, so /%61pi/auth/login is
     * /api/auth/login by the time a route runs. Checking the raw path would let
     * a form body through to it.
     */
    expect(CrossSiteRequest::isFormBody('POST', '/%61pi/auth/login', 'application/x-www-form-urlencoded'))->toBeTrue()
        ->and(CrossSiteRequest::isFormBody('POST', '/%61%70%69/settings', 'text/plain'))->toBeTrue()
        ->and(CrossSiteRequest::isFormBody('POST', '/%77ebhooks/example', 'application/x-www-form-urlencoded'))->toBeFalse();
});

it('starts no session for the site API, and one for everything else', function (string $uri, bool $wanted): void {
    expect(App\Domain\Auth\SessionCookie::wantedFor($uri))->toBe($wanted);
})->with([
    'the site API' => ['/api/site/posts', false],
    'the site API with a query' => ['/api/site/posts?page=2', false],
    'the site API, percent-encoded' => ['/api/%73ite/posts', false],
    'the app API' => ['/api/auth/me', true],
    'a page' => ['/posts', true],
    'a path that only looks like it' => ['/api/sites', true],
]);
