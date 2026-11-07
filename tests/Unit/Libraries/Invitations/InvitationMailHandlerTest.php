<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Domain\Invitations\InvitationMail;
use App\Domain\Mail\MailSettings;
use App\Domain\Queue\InvalidJobPayload;
use App\Libraries\Invitations\InvitationMailHandler;
use App\Libraries\Invitations\InvitationNotFound;
use App\Libraries\Invitations\Invitations;
use App\Models\InvitationRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeIdentity;
use Tests\Support\Fakes\FakeMailer;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\TestCase;

/*
 * Emailing an invitation (docs/invitations.md; docs/mail.md, whose failure
 * list the numbers cite): the job holds the invitation's id, and the worker
 * issues the link as it sends.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock();
    $this->mailer = new FakeMailer();
    $this->settings = new MailSettings('Waffle <noreply@waffle.example.com>', 'https://waffle.example.com', 'Waffle');
    $this->repository = TestCase::container()->get(InvitationRepository::class);
    $this->invitations = new Invitations($this->repository, new FakeIdentity(), $this->clock, new FakeQueue());
    $this->handler = new InvitationMailHandler($this->invitations, $this->mailer, $this->settings);
    $this->owner = Factory::createUser(Role::Owner);
});

/** The token in an email's link. */
function linkToken(string $text): string
{
    preg_match('#' . preg_quote('https://waffle.example.com/invite/', '#') . '([A-Za-z0-9_-]{43})#', $text, $match);

    return $match[1] ?? '';
}

it('names its job, and keeps nothing but the invitation\'s id (4)', function (): void {
    $job = InvitationMail::of(7);

    expect(InvitationMail::type())->toBe('invitation-mail')
        ->and($job->payload())->toBe(['invitation_id' => 7])
        ->and(InvitationMail::fromPayload(['invitation_id' => 7])->invitationId)->toBe(7)
        ->and($job->merge(new RecordingJob(['z'])))->toBeInstanceOf(RecordingJob::class)
        ->and($this->handler->jobClass())->toBe(InvitationMail::class)
        ->and(fn () => InvitationMail::fromPayload([]))->toThrow(InvalidJobPayload::class)
        ->and(fn () => InvitationMail::fromPayload(['invitation_id' => '7']))->toThrow(InvalidJobPayload::class)
        ->and(fn () => InvitationMail::fromPayload(['invitation_id' => 0]))->toThrow(InvalidJobPayload::class);
});

it('emails the invited address a link that opens the invitation, on APP_URL (11, 12)', function (): void {
    $invitation = $this->invitations->invite($this->owner, Role::Owner, 'guest@example.test', 'administrator');

    $this->handler->handle(InvitationMail::of($invitation->id));
    $message = $this->mailer->sent[0]['message'];
    $token = linkToken($message->text);

    expect($this->mailer->sent)->toHaveCount(1)
        ->and($message->to)->toBe(['guest@example.test'])
        ->and($message->subject)->toBe('Your invitation to Waffle')
        ->and($message->text)->toContain('You have been invited to Waffle as an administrator.')
        ->and($message->text)->toContain('2026-01-08')
        ->and($this->invitations->open($token)->email)->toBe('guest@example.test');
});

it('keeps the link it had when the provider fails, so the retry sends a working one (7)', function (): void {
    $invitation = $this->invitations->invite($this->owner, Role::Owner, 'retry@example.test', 'user');
    [, $earlier] = $this->invitations->issueLink($invitation->id);
    $this->mailer->down = true;

    expect(fn () => $this->handler->handle(InvitationMail::of($invitation->id)))->toThrow(RuntimeException::class)
        ->and($this->invitations->open($earlier)->email)->toBe('retry@example.test');

    $this->mailer->down = false;
    $this->handler->handle(InvitationMail::of($invitation->id));

    expect($this->invitations->open(linkToken($this->mailer->sent[0]['message']->text))->email)->toBe('retry@example.test')
        ->and(fn () => $this->invitations->open($earlier))->toThrow(InvitationNotFound::class);
});

it('sends nothing for an invitation revoked, used or expired before the email went (2)', function (): void {
    $revoked = $this->invitations->invite($this->owner, Role::Owner, 'revoked-mail@example.test', 'user');
    $this->invitations->revoke($revoked->id, Role::Owner);
    $late = $this->invitations->invite($this->owner, Role::Owner, 'late-mail@example.test', 'user');
    $this->clock->advance('+8 days');

    $this->handler->handle(InvitationMail::of($revoked->id));
    $this->handler->handle(InvitationMail::of($late->id));
    $this->handler->handle(InvitationMail::of(999999));

    expect($this->mailer->sent)->toBe([]);
});
