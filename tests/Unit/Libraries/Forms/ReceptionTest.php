<?php

declare(strict_types=1);

use App\Domain\Forms\ContactForm;
use App\Domain\Forms\FormDefinition;
use App\Domain\Forms\FormDefinitions;
use App\Domain\Forms\InvalidForm;
use App\Domain\Forms\SiteStatus;
use App\Domain\Forms\SubmissionStatus;
use App\Libraries\Forms\FormsNotFound;
use App\Libraries\Forms\Reception;
use App\Libraries\Forms\Sites;
use App\Libraries\Forms\TooManySubmissions;
use App\Models\Forms\SiteRepository;
use App\Models\Forms\SubmissionRepository;
use Tests\Support\Fakes\FrozenClock;
use Tests\TestCase;

/* Receiving submissions, and managing sites (docs/forms.md), on the real tables. */

beforeEach(function (): void {
    $this->clock = new FrozenClock();
    $this->submissions = TestCase::container()->get(SubmissionRepository::class);
    $this->sites = new Sites(TestCase::container()->get(SiteRepository::class), $this->clock);
    $this->reception = new Reception(new FormDefinitions(new FormDefinition('contact', 'Contact', 'Thanks!', new ContactForm())), $this->submissions, $this->clock);
    [$this->site] = $this->sites->issueKey($this->sites->create('reception-site', 'Reception', 'https://reception.example')->id);
});

/** @return array<string,string> */
function contact(array $overrides = []): array
{
    return [...['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello.'], ...$overrides];
}

it('keeps a good submission as new, with only the form\'s fields (6)', function (): void {
    $thanks = $this->reception->receive($this->site, 'contact', contact(['extra' => 'x']), '203.0.113.7');
    $kept = $this->submissions->withStatus(SubmissionStatus::New)[0];

    expect($thanks)->toBe('Thanks!')
        ->and($kept->form)->toBe('contact')
        ->and($kept->siteId)->toBe($this->site->id)
        ->and($kept->payload)->toBe(['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hello.']);
});

it('answers a filled honeypot like a good submission, and keeps nothing (3)', function (): void {
    $before = count($this->submissions->withStatus(SubmissionStatus::New));

    expect($this->reception->receive($this->site, 'contact', contact(['company_website' => 'spam.example']), '203.0.113.7'))->toBe('Thanks!')
        ->and(count($this->submissions->withStatus(SubmissionStatus::New)))->toBe($before);
});

it('takes five from one address in ten minutes, then refuses until the window passes (4)', function (): void {
    foreach (range(1, 5) as $n) {
        $this->reception->receive($this->site, 'contact', contact(), '203.0.113.8');
    }

    expect(fn () => $this->reception->receive($this->site, 'contact', contact(), '203.0.113.8'))->toThrow(TooManySubmissions::class)
        ->and($this->reception->receive($this->site, 'contact', contact(), '203.0.113.9'))->toBe('Thanks!');

    $this->clock->advance('+11 minutes');

    expect($this->reception->receive($this->site, 'contact', contact(), '203.0.113.8'))->toBe('Thanks!');
});

it('refuses a form it does not declare, and fields its form refuses (6, 7)', function (): void {
    expect(fn () => $this->reception->receive($this->site, 'quote', contact(), '203.0.113.7'))->toThrow(FormsNotFound::class)
        ->and(fn () => $this->reception->receive($this->site, 'contact', contact(['email' => 'x']), '203.0.113.7'))->toThrow(InvalidForm::class);
});

it('never stores the visitor\'s address (5)', function (): void {
    $this->reception->receive($this->site, 'contact', contact(), '198.51.100.23');

    $stored = TestCase::pdo()->query('SELECT form_submissions_ip_hash FROM form_submissions ORDER BY form_submissions_id DESC LIMIT 1')->fetchColumn();

    expect($stored)->toMatch('/^[0-9a-f]{64}$/')
        ->and($stored)->not->toContain('198.51.100.23');
});

it('makes a site, refuses a second with its slug, replaces a key at once, and disables it (2, 13)', function (): void {
    $repository = TestCase::container()->get(SiteRepository::class);
    $old = $repository->find($this->site->id)->keyHash;
    [$site, $key] = $this->sites->issueKey($this->site->id);

    expect(fn () => $this->sites->create('reception-site', 'Again', 'https://again.example'))->toThrow(InvalidForm::class, "There is already a site called 'reception-site'.")
        ->and($site->keyHash)->toBe(hash('sha256', $key))
        ->and($repository->activeByKeyHash($old))->toBeNull()
        ->and($repository->activeByKeyHash($site->keyHash)->id)->toBe($site->id)
        ->and($site->toArray())->not->toHaveKey('key');

    $this->sites->setStatus($site->id, SiteStatus::Disabled);

    expect($repository->activeByKeyHash($site->keyHash))->toBeNull()
        ->and(fn () => $this->sites->issueKey(999_999))->toThrow(FormsNotFound::class);
});

it('passes on a database error that is not a taken slug', function (): void {
    $broken = new Sites(new SiteRepository(new class () extends PDO {
        public function __construct()
        {
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $e = new PDOException('gone away');
            $e->errorInfo = ['HY000', 2006, 'gone away'];

            throw $e;
        }
    }, TestCase::container()->get(App\Models\TypeRepository::class)), $this->clock);

    expect(fn () => $broken->create('x', 'X', 'https://x.example'))->toThrow(PDOException::class, 'gone away');
});
