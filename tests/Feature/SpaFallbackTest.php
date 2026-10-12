<?php

declare(strict_types=1);

/*
 * A GET for a path the router does not know, outside the API prefixes, is a
 * deep link into the single-page app and gets its shell. Anything under /api,
 * /cron or /webhooks gets the JSON envelope instead.
 *
 * Whether the shell has been built (src/public/index.html is build output)
 * decides between 200 and 503 here; both branches are pinned down in
 * tests/Unit/Http/SpaShellTest.php.
 */

it('answers an unknown API path with a JSON 404', function (string $path): void {
    $response = $this->get($path);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getHeaderLine('Content-Type'))->toStartWith('application/json')
        ->and($this->json($response)['success'])->toBeFalse();
})->with(['/api/nothing-here', '/cron/nothing-here', '/webhooks/nothing-here', '/api']);

it('answers a deep link with the SPA shell rather than JSON', function (): void {
    $response = $this->get('/settings');

    expect($response->getStatusCode())->toBeIn([200, 503])
        ->and($response->getHeaderLine('Content-Type'))->toStartWith('text/');
});

it('answers an unknown path with JSON when it is not a GET', function (): void {
    $response = $this->post('/settings', ['x' => 1]);

    expect($response->getStatusCode())->toBe(404)
        ->and($this->json($response)['success'])->toBeFalse();
});
