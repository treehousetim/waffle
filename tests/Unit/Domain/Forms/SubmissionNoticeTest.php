<?php

declare(strict_types=1);

use App\Domain\Forms\SubmissionNotice;
use App\Domain\Mail\MailSettings;

/* The email a form's submission sends to whoever may read the Inbox (docs/forms.md; docs/mail.md, 9., 10., 12.). */

function noticeSettings(): MailSettings
{
    return new MailSettings('Waffle <noreply@waffle.example.com>', 'https://waffle.example.com', 'Waffle');
}

it('is one email per reader, each to that reader alone, a reply going to the sender (10, 12)', function (): void {
    $messages = SubmissionNotice::messages('Waffle site', 'Contact', ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => "Hello.\nA second line."], ['owner@example.test', 'admin@example.test'], noticeSettings());

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->to)->toBe(['owner@example.test'])
        ->and($messages[1]->to)->toBe(['admin@example.test'])
        ->and($messages[0]->replyTo)->toBe('ada@example.test')
        ->and($messages[0]->subject)->toBe('Contact form: a message from Ada')
        ->and($messages[0]->text)->toContain('Waffle site received a message through its Contact form.')
        ->and($messages[0]->text)->toContain("Name: Ada\nEmail: ada@example.test\n\nMessage:\nHello.\nA second line.\n")
        ->and($messages[0]->text)->toContain('https://waffle.example.com/inbox')
        ->and($messages[0]->text)->not->toContain('admin@example.test');
});

it('names no sender and sets no reply-to when the form has no usable address (10)', function (): void {
    [$message] = SubmissionNotice::messages('Site', 'Feedback', ['email' => 'not an address', 'rating' => '5'], ['owner@example.test'], noticeSettings());

    expect($message->replyTo)->toBeNull()
        ->and($message->subject)->toBe('Feedback form: a new message')
        ->and($message->text)->toContain("Email: not an address\nRating: 5\n");
});

it('sends nothing when nobody may read the Inbox (9)', function (): void {
    expect(SubmissionNotice::messages('Site', 'Contact', ['name' => 'Ada'], [], noticeSettings()))->toBe([]);
});

it('keeps a long name to a subject\'s length, and joins a multi-part field name into words', function (): void {
    [$message] = SubmissionNotice::messages('Site', 'Contact', ['name' => str_repeat('a', 300), 'preferred_time' => 'Evenings'], ['owner@example.test'], noticeSettings());

    expect(mb_strlen($message->subject))->toBeLessThanOrEqual(200)
        ->and($message->text)->toContain('Preferred time: Evenings');
});

it('leaves out a field that is not text, such as a list', function (): void {
    [$message] = SubmissionNotice::messages('Site', 'Contact', ['name' => 'Ada', 'topics' => ['a', 'b']], ['owner@example.test'], noticeSettings());

    expect($message->text)->toContain('Name: Ada')
        ->not->toContain('Topics');
});
