<?php

declare(strict_types=1);

use ModuleTool\Download;
use ModuleTool\Refusal;
use Tests\Support\FakeTransport;
use Tests\Support\ModuleFixtures;

/* A `zip` or `file` source (docs/module-command.md, 9). */

beforeEach(function (): void {
    $this->base = ModuleFixtures::base();
    $this->file = $this->base . '/download';
});

afterEach(function (): void {
    ModuleFixtures::remove($this->base);
});

it('fetches over HTTPS and follows HTTPS redirects, relative ones too (9)', function (): void {
    $transport = new FakeTransport([
        'https://example.org/notes.zip' => [302, 'https://cdn.example.org/a', ''],
        'https://cdn.example.org/a' => [301, '/b', ''],
        'https://cdn.example.org/b' => [200, null, 'zip bytes'],
    ]);

    Download::fetch($transport, 'https://example.org/notes.zip', $this->file);

    expect(file_get_contents($this->file))->toBe('zip bytes')
        ->and($transport->requests)->toBe(['https://example.org/notes.zip', 'https://cdn.example.org/a', 'https://cdn.example.org/b']);
});

it('refuses plain HTTP, at the start or after a redirect (9)', function (array $responses, string $url): void {
    expect(fn () => Download::fetch(new FakeTransport($responses), $url, $this->file))->toThrow(Refusal::class, 'only HTTPS downloads')
        ->and(file_exists($this->file))->toBeFalse();
})->with([
    'plain HTTP' => [[], 'http://example.org/notes.zip'],
    'a redirect to HTTP' => [['https://example.org/notes.zip' => [302, 'http://example.org/notes.zip', '']], 'https://example.org/notes.zip'],
    'a protocol-relative redirect' => [['https://example.org/notes.zip' => [302, '//evil.example/x', '']], 'https://example.org/notes.zip'],
    'a redirect with no location' => [['https://example.org/notes.zip' => [302, null, '']], 'https://example.org/notes.zip'],
]);

it('refuses more than five redirects (9)', function (): void {
    $responses = [];

    for ($i = 0; $i <= 6; $i++) {
        $responses["https://example.org/{$i}"] = [302, 'https://example.org/' . ($i + 1), ''];
    }

    expect(fn () => Download::fetch(new FakeTransport($responses), 'https://example.org/0', $this->file))->toThrow(Refusal::class, 'more than 5 times');
});

it('refuses a download over the limit (9)', function (): void {
    $transport = new FakeTransport(['https://example.org/big' => [200, null, str_repeat('a', 2049)]]);

    expect(fn () => Download::fetch($transport, 'https://example.org/big', $this->file, 2048))->toThrow(Refusal::class, 'larger than 0 MB');
});

it('refuses an answer other than 200 (9)', function (): void {
    $transport = new FakeTransport(['https://example.org/gone' => [404, null, 'not found']]);

    expect(fn () => Download::fetch($transport, 'https://example.org/gone', $this->file))->toThrow(Refusal::class, 'answered 404');
});
