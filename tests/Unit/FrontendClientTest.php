<?php

declare(strict_types=1);

/*
 * The frontend's API client, asserted by shape: Waffle's suite drives the API
 * directly, never the browser frontend, so this is where a frontend request
 * the API would refuse is caught.
 *
 * The API refuses a form-encoded write to /api (415, Domain/Auth/
 * CrossSiteRequest). axios labels a POST with no body a form, so sign-out
 * failed in every browser until the client declared JSON.
 */

function frontendFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/src/frontend/src/' . $path);
}

it('sends every request body as JSON unless a request says otherwise', function (): void {
    expect(frontendFile('api/client.js'))->toMatch("/headers: \\{[^}]*'Content-Type': 'application\\/json'/s");
});

it('signs out with a JSON body, which the API accepts', function (): void {
    expect(frontendFile('stores/auth.js'))->toContain("client.post('/auth/logout', {})");
});
