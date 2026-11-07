<?php

declare(strict_types=1);

use App\Domain\Auth\PasswordResetMail;
use App\Domain\Auth\Role;
use App\Domain\Mail\MailSettings;
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
use Tests\TestCase;

/*
 * Two uses of one reset link at once, on two connections
 * (docs/password-reset.md, 10.). Not transaction-wrapped: it commits, and
 * cleans up after itself.
 */

function resetsOn(PDO $pdo, FakeIdentity $identity, FakeQueue $queue): PasswordResets
{
    return new PasswordResets(new PasswordResetRepository($pdo), new UserRepository($pdo), $identity, new FrozenClock(), $queue, TestCase::container()->get(MailSettings::class));
}

/** Leaves nothing behind, even after a failed run: this test commits. */
function forgetSchemaReset(): void
{
    TestCase::pdo()->exec("DELETE FROM password_resets WHERE password_resets_user_id IN (SELECT id FROM users WHERE email = 'schema-reset@example.test')");
    TestCase::pdo()->exec("DELETE FROM users WHERE email = 'schema-reset@example.test'");
}

beforeEach(fn () => forgetSchemaReset());
afterEach(fn () => forgetSchemaReset());

it('lets one of two uses of the same link at once set the password; the other waits, then finds it used (10)', function (): void {
    $user = Factory::createUser(Role::User, 'schema-reset@example.test');
    $first = new FakeIdentity();
    $second = new FakeIdentity();
    $queue = new FakeQueue();
    $mailer = new FakeMailer();
    $resets = resetsOn(TestCase::pdo(), $first, $queue);
    $resets->request('schema-reset@example.test');
    (new PasswordResetMailHandler($resets, $mailer, TestCase::container()->get(MailSettings::class)))->handle($queue->enqueued[0]['job']);
    preg_match('#/reset/([A-Za-z0-9_-]{43})#', $mailer->sent[0]['message']->text, $match);
    $token = $match[1];

    $other = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], (int) $_ENV['DB_PORT'], $_ENV['DB_NAME']),
        (string) $_ENV['DB_USER'],
        (string) $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

    TestCase::pdo()->beginTransaction();
    resetsOn(TestCase::pdo(), $first, $queue)->reset($token, 'the first new passphrase');

    expect(fn () => resetsOn($other, $second, new FakeQueue())->reset($token, 'the second new passphrase'))
        ->toThrow(PDOException::class, 'Lock wait timeout');

    TestCase::pdo()->commit();

    expect(fn () => resetsOn($other, $second, new FakeQueue())->reset($token, 'the second new passphrase'))
        ->toThrow(ResetNotFound::class)
        ->and($first->passwordsChanged)->toBe([$user => 'the first new passphrase'])
        ->and($second->passwordsChanged)->toBe([]);
});
