<?php

declare(strict_types=1);

use App\Domain\Forms\ContactForm;
use App\Domain\Forms\FormDefinition;
use App\Domain\Forms\FormDefinitions;
use App\Domain\Forms\InvalidForm;
use App\Domain\Forms\SiteInput;
use App\Domain\Forms\SiteKey;
use App\Domain\Forms\SitePath;
use App\Domain\Forms\SiteStatus;
use App\Domain\Forms\SubmissionStatus;
use App\Domain\Forms\Visitor;
use App\Domain\Status\IllegalTransition;

/* Forms' rules (docs/forms.md). The numbers are the failure list's. */

it('marks a submission once, never from spam to handled (12)', function (): void {
    expect(SubmissionStatus::New->allowedNext())->toBe([SubmissionStatus::Handled, SubmissionStatus::Spam])
        ->and(SubmissionStatus::Spam->isTerminal())->toBeTrue()
        ->and(SubmissionStatus::Handled->isTerminal())->toBeTrue()
        ->and(fn () => SubmissionStatus::Spam->assertCanTransitionTo(SubmissionStatus::Handled))->toThrow(IllegalTransition::class)
        ->and(SiteStatus::Active->allowedNext())->toBe([SiteStatus::Disabled])
        ->and(SiteStatus::Disabled->allowedNext())->toBe([SiteStatus::Active]);
});

it('issues a site key once and keeps only its hash (2)', function (): void {
    [$key, $hash] = SiteKey::issue();

    expect($key)->toMatch('/^wsk_[A-Za-z0-9_-]{43}$/')
        ->and($hash)->toBe(hash('sha256', $key))
        ->and(SiteKey::hash($key))->toBe($hash)
        ->and(SiteKey::hash('local-site-key'))->toBeNull()
        ->and(SiteKey::hash(''))->toBeNull();
});

it('hashes a visitor with the day and the site, and reads the address the site\'s server wrote (5)', function (): void {
    $today = Visitor::hash('203.0.113.7', '2026-01-01', 1, 'key');

    expect($today)->toBe(hash_hmac('sha256', '203.0.113.7|2026-01-01|1', 'key'))
        ->and(Visitor::hash('203.0.113.7', '2026-01-02', 1, 'key'))->not->toBe($today)
        ->and(Visitor::hash('203.0.113.7', '2026-01-01', 2, 'key'))->not->toBe($today)
        ->and(Visitor::hash('203.0.113.7', '2026-01-01', 1, 'another key'))->not->toBe($today)
        ->and(Visitor::address(' 203.0.113.7 ', '10.0.0.9'))->toBe('203.0.113.7')
        ->and(Visitor::address('203.0.113.7, 10.0.0.2', '10.0.0.9'))->toBe('10.0.0.9')
        ->and(Visitor::address('not an address', '10.0.0.9'))->toBe('10.0.0.9')
        ->and(Visitor::address('', '10.0.0.9'))->toBe('10.0.0.9');
});

it('sends a browser back only to a plain path on the site (9)', function (mixed $path, string $expected): void {
    expect(SitePath::or($path))->toBe($expected);
})->with([
    'a thanks page' => ['/contact/thanks/', '/contact/thanks/'],
    'home' => ['/', '/'],
    'another site' => ['https://evil.example/', '/'],
    'protocol-relative' => ['//evil.example/', '/'],
    'a double slash inside' => ['/a//b', '/'],
    'a climb' => ['/../x', '/'],
    'a query' => ['/thanks?x=1', '/'],
    'nothing' => [null, '/'],
]);

it('takes a contact form\'s name, email and message, trimmed, and nothing else (6)', function (): void {
    $fields = (new ContactForm())->parse(['name' => ' Ada ', 'email' => 'ada@example.test', 'message' => "Hello\r\nthere", 'admin' => '1']);

    expect($fields)->toBe(['name' => 'Ada', 'email' => 'ada@example.test', 'message' => "Hello\nthere"]);
});

it('names every field of a contact form it refuses (6)', function (): void {
    try {
        (new ContactForm())->parse(['name' => '', 'email' => 'nope', 'message' => str_repeat('a', 5001)]);
        $fields = [];
    } catch (InvalidForm $e) {
        $fields = $e->fields;
        $message = $e->getMessage();
    }

    expect($message)->toBe('Please check the form.')
        ->and($fields)->toBe([
            'name' => 'Please fill this in.',
            'email' => 'Please give an email address we can reply to.',
            'message' => 'This can be at most 5000 characters.',
        ])
        ->and(fn () => (new ContactForm())->parse(['name' => "A\x07", 'email' => 'a@example.test', 'message' => 'x']))
        ->toThrow(InvalidForm::class);
});

it('finds declared forms by name only (7)', function (): void {
    $contact = new FormDefinition('contact', 'Contact', 'Thanks.', new ContactForm());
    $forms = new FormDefinitions($contact);

    expect($forms->find('contact'))->toBe($contact)
        ->and($forms->find('quote'))->toBeNull();
});

it('takes a site with a slug, a name and an https origin (13)', function (): void {
    $site = SiteInput::parse('www-site', ' Our site ', 'https://www.example.com/');

    expect([$site->slug, $site->name, $site->origin])->toBe(['www-site', 'Our site', 'https://www.example.com'])
        ->and(SiteInput::parse('dev', 'Dev', 'http://localhost:4321')->origin)->toBe('http://localhost:4321');
});

it('refuses a site without a usable slug, name or origin (13)', function (mixed $slug, mixed $name, mixed $origin, string $message): void {
    expect(fn () => SiteInput::parse($slug, $name, $origin))->toThrow(InvalidForm::class, $message);
})->with([
    'a bad slug' => ['Bad Slug', 'A', 'https://a.example', 'The slug can hold only'],
    'no name' => ['a', ' ', 'https://a.example', 'Give the site a name'],
    'plain http' => ['a', 'A', 'http://a.example', 'The origin must be https://'],
    'a path' => ['a', 'A', 'https://a.example/path', 'The origin must be https://'],
    'not text' => ['a', 'A', ['x'], 'The origin must be https://'],
]);
