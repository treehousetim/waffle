<?php

declare(strict_types=1);

use App\Libraries\Http\ErrorHandler;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Http\SpaShell;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

beforeEach(function (): void {
    $this->shellPath = tempnam(sys_get_temp_dir(), 'shell-') . '.html';
    file_put_contents($this->shellPath, '<!doctype html><title>Waffle</title>');

    $factory = new ResponseFactory();
    $this->handler = new ErrorHandler(
        new JsonResponder($factory),
        new SpaShell($factory, $this->shellPath),
    );

    $this->makeRequest = fn (string $method, string $path) => (new ServerRequestFactory())
        ->createServerRequest($method, 'http://localhost' . $path);

    $this->run = function (string $method, string $path, Throwable $e, bool $details = false) {
        $response = ($this->handler)(($this->makeRequest)($method, $path), $e, $details, false, false);
        $response->getBody()->rewind();

        return $response;
    };
});

afterEach(function (): void {
    if (is_file($this->shellPath)) {
        unlink($this->shellPath);
    }
});

it('answers an unmatched GET outside the API with the SPA shell', function (string $path): void {
    $request = ($this->makeRequest)('GET', $path);
    $response = ($this->run)('GET', $path, new HttpNotFoundException($request));

    expect($response->getStatusCode())->toBe(200)
        ->and((string) $response->getBody())->toContain('<title>Waffle</title>');
})->with([
    'a deep link' => ['/settings/general'],
    'the root' => ['/'],
    // Shares a prefix with /api but is not under it, so it is still an SPA route.
    'a path that merely starts like an API path' => ['/apiary'],
]);

it('answers an unmatched API path with a JSON 404, never the shell', function (string $path): void {
    $request = ($this->makeRequest)('GET', $path);
    $response = ($this->run)('GET', $path, new HttpNotFoundException($request));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and(json_decode((string) $response->getBody(), true)['success'])->toBeFalse();
})->with([
    'an api route' => ['/api/nope'],
    'the bare api prefix' => ['/api'],
    'a cron route' => ['/cron/nope'],
    'a webhook route' => ['/webhooks/nope'],
]);

it('never serves the shell for a non-GET request', function (): void {
    $request = ($this->makeRequest)('POST', '/settings');
    $response = ($this->run)('POST', '/settings', new HttpNotFoundException($request));

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8');
});

it('passes through the status and message of any other HTTP exception', function (): void {
    $request = ($this->makeRequest)('PATCH', '/api/hello');
    $response = ($this->run)('PATCH', '/api/hello', new HttpMethodNotAllowedException($request));

    expect($response->getStatusCode())->toBe(405)
        ->and(json_decode((string) $response->getBody(), true)['error'])->toBeString()->not->toBeEmpty();
});

it('hides the detail of an unexpected throwable by default', function (): void {
    $response = ($this->run)('GET', '/api/hello', new RuntimeException('connection string: secret'));

    expect($response->getStatusCode())->toBe(500)
        ->and(json_decode((string) $response->getBody(), true)['error'])->toBe('Internal server error.');
});

it('reveals the detail of an unexpected throwable when APP_DEBUG is on', function (): void {
    $response = ($this->run)('GET', '/api/hello', new RuntimeException('boom'), true);

    expect($response->getStatusCode())->toBe(500)
        ->and(json_decode((string) $response->getBody(), true)['error'])->toBe('boom');
});
