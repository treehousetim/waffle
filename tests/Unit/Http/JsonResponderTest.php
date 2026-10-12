<?php

declare(strict_types=1);

use App\Libraries\Http\JsonResponder;
use Slim\Psr7\Factory\ResponseFactory;

beforeEach(function (): void {
    $this->responder = new JsonResponder(new ResponseFactory());
});

it('wraps data in the success envelope', function (): void {
    $response = $this->responder->success(['answer' => 42]);
    $response->getBody()->rewind();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json; charset=utf-8')
        ->and(json_decode((string) $response->getBody(), true))
        ->toBe(['success' => true, 'data' => ['answer' => 42]]);
});

it('honours a non-default success status', function (): void {
    expect($this->responder->success(null, 201)->getStatusCode())->toBe(201);
});

it('wraps a message in the error envelope', function (): void {
    $response = $this->responder->error('Nope.', 418);
    $response->getBody()->rewind();

    expect($response->getStatusCode())->toBe(418)
        ->and(json_decode((string) $response->getBody(), true))
        ->toBe(['success' => false, 'error' => 'Nope.']);
});

it('defaults an error to 400', function (): void {
    expect($this->responder->error('Bad.')->getStatusCode())->toBe(400);
});

it('carries a machine-readable code when one is given', function (): void {
    // For the failures the frontend has to act on rather than display. Two
    // different 401s need opposite responses -- sign in, versus finish signing
    // in -- and telling them apart by message text meant a reworded error
    // silently changed behaviour.
    $response = $this->responder->error('Finish signing in.', 401, 'passkey_required');
    $response->getBody()->rewind();

    expect(json_decode((string) $response->getBody(), true))
        ->toBe(['success' => false, 'error' => 'Finish signing in.', 'code' => 'passkey_required']);
});

it('leaves the code out entirely when there is none', function (): void {
    // Absent, not null: a consumer checking `'code' in body` should get false.
    $response = $this->responder->error('Nope.');
    $response->getBody()->rewind();

    expect(json_decode((string) $response->getBody(), true))->not->toHaveKey('code');
});

it('writes into a response it was handed rather than creating one', function (): void {
    $existing = (new ResponseFactory())->createResponse()->withHeader('X-Trace', 'abc');

    $response = $this->responder->write($existing, ['success' => true, 'data' => null], 202);
    $response->getBody()->rewind();

    expect($response->getStatusCode())->toBe(202)
        ->and($response->getHeaderLine('X-Trace'))->toBe('abc')
        ->and((string) $response->getBody())->toBe('{"success":true,"data":null}');
});

it('leaves slashes and unicode unescaped so URLs stay readable', function (): void {
    $response = $this->responder->success(['url' => 'https://example.com/a/b', 'name' => 'café']);
    $response->getBody()->rewind();

    expect((string) $response->getBody())
        ->toContain('https://example.com/a/b')
        ->toContain('café');
});
