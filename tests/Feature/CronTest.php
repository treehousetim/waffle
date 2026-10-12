<?php

declare(strict_types=1);

it('reports the database and its schema to a caller with the cron secret', function (): void {
    $response = $this->withCronSecret()->get('/cron/health');
    $body = $this->json($response);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['data']['database'])->toBe('ok')
        // The newest migration's version, e.g. "20261012000004".
        ->and($body['data']['migration'])->toMatch('/^\d{14}$/');
});

it('refuses a caller with no cron secret', function (): void {
    $response = $this->get('/cron/health');

    expect($response->getStatusCode())->toBe(403)
        ->and($this->json($response))->toBe(['success' => false, 'error' => 'Invalid cron secret.']);
});

it('refuses a caller with the wrong cron secret', function (): void {
    expect($this->withCronSecret('not-the-secret')->get('/cron/health')->getStatusCode())->toBe(403);
});

it('does not accept the secret as a query parameter', function (): void {
    // A secret in a URL is a secret in every access log.
    expect($this->get('/cron/health', ['secret' => $_ENV['CRON_SECRET']])->getStatusCode())->toBe(403);
});

it('does not let a signed-in session stand in for the cron secret', function (): void {
    $this->asOwner();

    expect($this->get('/cron/health')->getStatusCode())->toBe(403);
});
