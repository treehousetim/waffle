<?php

declare(strict_types=1);

namespace App\Libraries\Forms;

use App\Domain\Forms\FormDefinitions;
use App\Domain\Forms\InvalidForm;
use App\Domain\Forms\Visitor;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\StoragePort;
use App\Models\Forms\Site;
use App\Models\Forms\SubmissionRepository;

/**
 * Receiving a submission from a site (docs/forms.md, "How a submission is
 * received"): the honeypot, the rate limit, the form's own parser, then the
 * inbox.
 */
final readonly class Reception
{
    /** The field people leave empty and bots fill (Waffle Site's docs/forms.md). */
    public const string HONEYPOT = 'company_website';

    public const int LIMIT = 5;

    public const string WINDOW = '-10 minutes';

    /** Where the visitor hash's key is kept: in storage, not the database (5.). */
    public const string KEY = 'forms/visitor-key';

    public function __construct(
        private FormDefinitions $forms,
        private SubmissionRepository $submissions,
        private ClockPort $clock,
        private StoragePort $storage,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return string the thanks message
     *
     * @throws FormsNotFound|TooManySubmissions|InvalidForm
     */
    public function receive(Site $site, string $name, array $input, string $address): string
    {
        $form = $this->forms->find($name) ?? throw new FormsNotFound('There is no form called that.');
        $now = $this->clock->now();

        // 3. A bot learns nothing: the same answer, and nothing kept.
        if (trim((string) (is_scalar($input[self::HONEYPOT] ?? null) ? $input[self::HONEYPOT] : '')) !== '') {
            return $form->thanks;
        }

        $visitor = Visitor::hash($address, $now->format('Y-m-d'), $site->id, $this->visitorKey());

        if ($this->submissions->countSince($visitor, $now->modify(self::WINDOW)) >= self::LIMIT) {
            throw new TooManySubmissions('Too many tries. Please wait a few minutes and send it again.');
        }

        $this->submissions->insert($form->name, $site->id, $form->fields->parse($input), $visitor, $now);

        return $form->thanks;
    }

    /** The visitor hash's key: made once, at random, the first time it is needed. */
    private function visitorKey(): string
    {
        $key = $this->storage->get(self::KEY);

        if ($key === null) {
            $key = bin2hex(random_bytes(32));
            $this->storage->put(self::KEY, $key);
        }

        return $key;
    }
}
