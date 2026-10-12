<?php

declare(strict_types=1);

use App\Libraries\Http\SpaShell;
use Slim\Psr7\Factory\ResponseFactory;

it('serves the built shell with caching disabled', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'shell-') . '.html';
    file_put_contents($path, '<!doctype html><title>Waffle</title>');

    $shell = new SpaShell(new ResponseFactory(), $path);
    $response = $shell->response();
    $response->getBody()->rewind();

    expect($shell->isBuilt())->toBeTrue()
        ->and($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/html; charset=utf-8')
        ->and($response->getHeaderLine('Cache-Control'))->toBe('no-cache, no-store, must-revalidate')
        ->and((string) $response->getBody())->toBe('<!doctype html><title>Waffle</title>');

    unlink($path);
});

it('explains how to build the frontend when it has not been built', function (): void {
    $shell = new SpaShell(new ResponseFactory(), '/nonexistent/app/index.html');
    $response = $shell->response();
    $response->getBody()->rewind();

    expect($shell->isBuilt())->toBeFalse()
        ->and($response->getStatusCode())->toBe(503)
        ->and($response->getHeaderLine('Content-Type'))->toBe('text/plain; charset=utf-8')
        ->and((string) $response->getBody())->toContain('./tools/npm.sh run build');
});
