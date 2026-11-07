<?php

declare(strict_types=1);

namespace App\Libraries\Forms;

use App\Domain\Auth\Permission;
use App\Domain\Auth\Permissions;
use App\Domain\Auth\Role;
use App\Domain\Forms\FormDefinitions;
use App\Domain\Forms\InvalidForm;
use App\Domain\Forms\SubmissionNotice;
use App\Domain\Forms\Visitor;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\SendMail;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Ports\StoragePort;
use App\Models\Forms\Site;
use App\Models\Forms\SubmissionRepository;
use App\Models\UserRepository;

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
        private UserRepository $users,
        private QueuePort $queue,
        private MailSettings $mail,
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
        $form = $this->forms->find($name);

        // A form the app files itself (Email) is not one a site may send (docs/mail.md, 15.).
        if ($form === null || !$form->fromSites) {
            throw new FormsNotFound('There is no form called that.');
        }
        $now = $this->clock->now();

        // 3. A bot learns nothing: the same answer, and nothing kept.
        if (trim((string) (is_scalar($input[self::HONEYPOT] ?? null) ? $input[self::HONEYPOT] : '')) !== '') {
            return $form->thanks;
        }

        $visitor = Visitor::hash($address, $now->format('Y-m-d'), $site->id, $this->visitorKey());

        if ($this->submissions->countSince($visitor, $now->modify(self::WINDOW)) >= self::LIMIT) {
            throw new TooManySubmissions('Too many tries. Please wait a few minutes and send it again.');
        }

        $fields = $form->fields->parse($input);
        $readers = $this->users->addressesWithAnyRole(array_values(array_filter(
            Role::cases(),
            static fn (Role $role): bool => in_array(Permission::FormsManage, Permissions::for($role), true),
        )));

        // The submission and its emails together, or neither (docs/mail.md, 2.).
        $this->submissions->transactional(function () use ($form, $site, $fields, $visitor, $now, $readers): void {
            $this->submissions->insert($form->name, $site->id, $fields, $visitor, $now);

            foreach (SubmissionNotice::messages($site->name, $form->label, $fields, $readers, $this->mail) as $message) {
                $this->queue->enqueue(SendMail::of($message));
            }
        });

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
