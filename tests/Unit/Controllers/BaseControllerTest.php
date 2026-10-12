<?php

declare(strict_types=1);

use App\Libraries\Http\JsonResponder;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\ExampleController;

beforeEach(function (): void {
    $this->controller = new ExampleController(new JsonResponder(new ResponseFactory()));
    $this->response = fn () => (new ResponseFactory())->createResponse();
    $this->request = fn () => (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost/api/x');

    $this->decode = function ($response): array {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    };
});

it('writes the success envelope into the response Slim supplied', function (): void {
    $response = $this->controller->success(($this->response)(), ['ok' => true]);

    expect($response->getStatusCode())->toBe(200)
        ->and(($this->decode)($response))->toBe(['success' => true, 'data' => ['ok' => true]]);
});

it('defaults success data to null', function (): void {
    expect(($this->decode)($this->controller->success(($this->response)())))
        ->toBe(['success' => true, 'data' => null]);
});

it('honours a custom success status', function (): void {
    expect($this->controller->success(($this->response)(), null, 201)->getStatusCode())->toBe(201);
});

it('writes the error envelope', function (): void {
    $response = $this->controller->failure(($this->response)(), 'Nope.', 422);

    expect($response->getStatusCode())->toBe(422)
        ->and(($this->decode)($response))->toBe(['success' => false, 'error' => 'Nope.']);
});

it('defaults an error to 400', function (): void {
    expect($this->controller->failure(($this->response)(), 'Bad.')->getStatusCode())->toBe(400);
});

it('returns a parsed array body as-is', function (): void {
    $request = ($this->request)()->withParsedBody(['a' => 1]);

    expect($this->controller->parsedBody($request))->toBe(['a' => 1]);
});

it('returns an empty array when the body is not an array', function (mixed $parsed): void {
    $request = ($this->request)()->withParsedBody($parsed);

    expect($this->controller->parsedBody($request))->toBe([]);
})->with([
    'null body' => [null],
    'object body' => [new stdClass()],
]);

it('trims a scalar string value', function (): void {
    expect($this->controller->stringValue(['name' => '  Ada  '], 'name'))->toBe('Ada');
});

it('stringifies a scalar that is not a string', function (): void {
    expect($this->controller->stringValue(['n' => 42], 'n'))->toBe('42')
        ->and($this->controller->stringValue(['b' => true], 'b'))->toBe('1');
});

it('falls back to the default for a missing or non-scalar string value', function (mixed $value): void {
    expect($this->controller->stringValue(['k' => $value], 'k', 'fallback'))->toBe('fallback')
        ->and($this->controller->stringValue([], 'k', 'fallback'))->toBe('fallback');
})->with([
    'null' => [null],
    'array' => [['a']],
]);

it('clamps an integer into its allowed range', function (mixed $given, int $expected): void {
    expect($this->controller->intValue(['limit' => $given], 'limit', 100, 1, 500))->toBe($expected);
})->with([
    'within range' => [250, 250],
    'below the floor' => [0, 1],
    'above the ceiling' => [9999, 500],
    'numeric string' => ['42', 42],
]);

it('falls back to the default for a missing or non-numeric integer value', function (mixed $value): void {
    expect($this->controller->intValue(['limit' => $value], 'limit', 100, 1, 500))->toBe(100)
        ->and($this->controller->intValue([], 'limit', 100, 1, 500))->toBe(100);
})->with([
    'null' => [null],
    'array' => [[1]],
    'non-numeric string' => ['many'],
]);
