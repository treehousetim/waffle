<?php

declare(strict_types=1);

use App\Domain\Auth\InvalidReset;
use App\Domain\Auth\PasswordResetMail;
use App\Domain\Auth\Role;
use App\Domain\Mail\MailSettings;
use App\Domain\Mail\SendMail;
use App\Domain\Queue\InvalidJobPayload;
use App\Libraries\Auth\PasswordResetMailHandler;
use App\Libraries\Auth\PasswordResets;
use App\Libraries\Auth\ResetNotFound;
use App\Models\PasswordResetRepository;
use App\Models\UserRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeIdentity;
use Tests\Support\Fakes\FakeMailer;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Fixtures\Queue\RecordingJob;
use Tests\TestCase;

/*
 * Password reset (docs/password-reset.md, whose failure list the numbers
 * cite) on the real tables, with a stand-in identity, queue and mailer.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock();
    $this->identity = new FakeIdentity();
    $this->queue = new FakeQueue();
    $this->mailer = new FakeMailer();
    $this->settings = new MailSettings('Waffle <noreply@waffle.example.com>', 'https://waffle.example.com', 'Waffle');
    $this->resets = new PasswordResets(
        TestCase::container()->get(PasswordResetRepository::class),
        TestCase::container()->get(UserRepository::class),
        $this->identity,
        $this->clock,
        $this->queue,
        $this->settings,
    );
    $this->handler = new PasswordResetMailHandler($this->resets, $this->mailer, $this->settings);
    $this->userId = Factory::createUser(Role::User, 'forgetful@example.test');
});

/** Asks for a reset and sends its email; the token in the link. */
function resetLink(object $test, string $email = 'forgetful@example.test'): string
{
    $before = count($test->mailer->sent);
    $test->resets->request($email);

    foreach ($test->queue->enqueued as $queued) {
        if ($queued['job'] instanceof PasswordResetMail) {
            $test->handler->handle($queued['job']);
        }
    }

    $test->queue->enqueued = [];
    $sent = array_slice($test->mailer->sent, $before);
    preg_match('#' . preg_quote('https://waffle.example.com/reset/', '#') . '([A-Za-z0-9_-]{43})#', $sent ? end($sent)['message']->text : '', $match);

    return $match[1] ?? '';
}

it('names its job, and keeps nothing but the request\'s id (3)', function (): void {
    expect(PasswordResetMail::type())->toBe('password-reset-mail')
        ->and(PasswordResetMail::of(4)->payload())->toBe(['reset_id' => 4])
        ->and(PasswordResetMail::fromPayload(['reset_id' => 4])->resetId)->toBe(4)
        ->and(PasswordResetMail::of(4)->merge(new RecordingJob(['z'])))->toBeInstanceOf(RecordingJob::class)
        ->and($this->handler->jobClass())->toBe(PasswordResetMail::class)
        ->and(fn () => PasswordResetMail::fromPayload(['reset_id' => 'x']))->toThrow(InvalidJobPayload::class);
});

it('queues an email holding only the request\'s id, and nothing for an address with no account (1, 3)', function (): void {
    $this->resets->request(' Forgetful@Example.TEST ');
    $this->resets->request('nobody@example.test');

    expect($this->queue->enqueued)->toHaveCount(1)
        ->and(array_keys($this->queue->enqueued[0]['job']->payload()))->toBe(['reset_id']);
});

it('refuses an address that is not one', function (): void {
    expect(fn () => $this->resets->request('not an address'))->toThrow(InvalidReset::class, 'Give a valid email address.')
        ->and(fn () => $this->resets->request(['a']))->toThrow(InvalidReset::class);
});

it('sends nothing to an account that is turned off or was never verified (5)', function (): void {
    $off = Factory::createUser(Role::User, 'off@example.test');
    Factory::disable($off);
    $unverified = Factory::createUnverifiedUser();
    $address = TestCase::container()->get(UserRepository::class)->findById($unverified)->email;

    $this->resets->request('off@example.test');
    $this->resets->request($address);

    expect($this->queue->enqueued)->toBe([]);
});

it('emails a link on APP_URL that opens, stored only as its hash (3; mail 11)', function (): void {
    $token = resetLink($this);
    $message = end($this->mailer->sent)['message'];
    $stored = TestCase::pdo()->query("SELECT password_resets_token_hash FROM password_resets WHERE password_resets_user_id = {$this->userId}")->fetchColumn();

    expect($message->to)->toBe(['forgetful@example.test'])
        ->and($message->subject)->toBe('Reset your Waffle password')
        ->and($message->text)->toContain('within an hour')
        ->and($this->resets->open($token))->toBe('forgetful@example.test')
        ->and($stored)->toBe(hash('sha256', $token));
});

it('sets a password once with a link, closes every other open link, and emails a notice (2, 7, 8)', function (): void {
    $older = resetLink($this);
    $this->clock->advance('+1 minute');
    $newer = resetLink($this);

    $this->resets->reset($newer, 'a much better password');
    $notice = $this->queue->enqueued[0]['job'] ?? null;

    expect($this->identity->passwordsChanged)->toBe([$this->userId => 'a much better password'])
        ->and(fn () => $this->resets->open($older))->toThrow(ResetNotFound::class)
        ->and(fn () => $this->resets->reset($newer, 'a much better password'))->toThrow(ResetNotFound::class)
        ->and($notice)->toBeInstanceOf(SendMail::class)
        ->and($notice->message->to)->toBe(['forgetful@example.test'])
        ->and($notice->message->subject)->toBe('Your Waffle password was changed');
});

it('closes an earlier link when a newer one is asked for (2)', function (): void {
    $older = resetLink($this);
    $this->clock->advance('+1 minute');
    $newer = resetLink($this);

    expect(fn () => $this->resets->open($older))->toThrow(ResetNotFound::class)
        ->and($this->resets->open($newer))->toBe('forgetful@example.test');
});

it('stops a link working after its hour, and answers every unusable link the same way (2)', function (): void {
    $token = resetLink($this);
    $this->clock->advance('+61 minutes');

    foreach ([$token, 'short', str_repeat('A', 43)] as $link) {
        expect(fn () => $this->resets->open($link))->toThrow(ResetNotFound::class, 'This link is not valid. Ask for a new one.');
    }
});

it('refuses a password shorter than 12 characters, and keeps the link (6)', function (): void {
    $token = resetLink($this);

    expect(fn () => $this->resets->reset($token, 'too short'))->toThrow(InvalidReset::class, 'Choose a password of at least 12 characters.')
        ->and(fn () => $this->resets->reset($token, ['x']))->toThrow(InvalidReset::class)
        ->and($this->resets->open($token))->toBe('forgetful@example.test')
        ->and($this->identity->passwordsChanged)->toBe([]);
});

it('sends at most three emails an hour to one account, with the same answer (4)', function (): void {
    foreach (range(1, 5) as $_) {
        $this->resets->request('forgetful@example.test');
    }

    expect($this->queue->enqueued)->toHaveCount(3);

    $this->clock->advance('+61 minutes');
    $this->resets->request('forgetful@example.test');

    expect($this->queue->enqueued)->toHaveCount(4);
});

it('keeps no link when the email fails to send, and sends nothing for a closed request (9)', function (): void {
    $this->resets->request('forgetful@example.test');
    $job = $this->queue->enqueued[0]['job'];
    $this->mailer->down = true;

    expect(fn () => $this->handler->handle($job))->toThrow(RuntimeException::class);

    $hash = TestCase::pdo()->query("SELECT password_resets_token_hash FROM password_resets WHERE password_resets_user_id = {$this->userId}")->fetchColumn();
    $this->mailer->down = false;
    $this->clock->advance('+61 minutes');
    $this->handler->handle($job);

    expect($hash)->toBeNull()
        ->and($this->mailer->sent)->toBe([]);

    $this->handler->handle(PasswordResetMail::of(999999));

    expect($this->mailer->sent)->toBe([]);
});
