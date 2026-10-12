<?php

declare(strict_types=1);

use App\Libraries\Http\JsonResponder;
use App\Libraries\Middleware\CronMiddleware;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\RecordingHandler;

beforeEach(function (): void {
    $this->json = new JsonResponder(new ResponseFactory());
    $this->handler = new RecordingHandler();
    $this->request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/cron/health');

    $this->body = function ($response): array {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    };
});

it('accepts the secret in the X-Cron-Secret header', function (): void {
    $middleware = new CronMiddleware($this->json, 's3cret');

    $response = $middleware->process($this->request->withHeader('X-Cron-Secret', 's3cret'), $this->handler);

    expect($this->handler->wasCalled)->toBeTrue()
        ->and($response->getStatusCode())->toBe(204);
});

it('refuses the secret in the query string', function (): void {
    /*
     * It used to accept it, for a convenience nothing used: deploy/crontab has
     * always sent the header. A secret in a URL is a secret in an access log, a
     * proxy log and a referrer -- and these endpoints drain the job queue and
     * run the provisioning planner, so the whole value of the credential is
     * that it does not turn up in a file somebody greps months later.
     */
    $middleware = new CronMiddleware($this->json, 's3cret');

    $response = $middleware->process($this->request->withQueryParams(['secret' => 's3cret']), $this->handler);

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403);
});

it('ignores the query string entirely, even alongside a good header', function (): void {
    $middleware = new CronMiddleware($this->json, 's3cret');

    $request = $this->request
        ->withHeader('X-Cron-Secret', 's3cret')
        ->withQueryParams(['secret' => 'wrong']);

    expect($middleware->process($request, $this->handler)->getStatusCode())->toBe(204);
});

it('rejects a wrong secret without calling the handler', function (): void {
    $middleware = new CronMiddleware($this->json, 's3cret');

    $response = $middleware->process($this->request->withHeader('X-Cron-Secret', 'nope'), $this->handler);

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and(($this->body)($response)['error'])->toBe('Invalid cron secret.');
});

it('rejects a request with no secret at all', function (): void {
    $middleware = new CronMiddleware($this->json, 's3cret');

    $response = $middleware->process($this->request, $this->handler);

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403);
});

it('rejects a non-string secret query parameter', function (): void {
    $middleware = new CronMiddleware($this->json, 's3cret');

    $response = $middleware->process($this->request->withQueryParams(['secret' => ['array']]), $this->handler);

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403);
});

it('fails closed when CRON_SECRET is empty, even if the caller sends nothing', function (): void {
    $middleware = new CronMiddleware($this->json, '');

    $response = $middleware->process($this->request, $this->handler);

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403)
        ->and(($this->body)($response)['error'])->toBe('Cron access is not configured.');
});

it('fails closed when CRON_SECRET is empty and the caller sends an empty secret', function (): void {
    $middleware = new CronMiddleware($this->json, '');

    $response = $middleware->process($this->request->withQueryParams(['secret' => '']), $this->handler);

    expect($this->handler->wasCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(403);
});
