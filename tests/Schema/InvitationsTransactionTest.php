<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Libraries\Invitations\InvitationNotFound;
use App\Libraries\Invitations\Invitations;
use App\Models\InvitationRepository;
use App\Models\TypeRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeIdentity;
use Tests\Support\Fakes\FrozenClock;
use Tests\TestCase;

/*
 * Accepting an invitation against real commits (docs/invitations.md, 11.).
 * Not transaction-wrapped, so everything made here is deleted here.
 */

afterEach(function (): void {
    $pdo = TestCase::pdo();

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $pdo->exec("DELETE FROM invitations WHERE invitations_email LIKE 'schema-invite-%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'schema-invite-%'");
});

function invitationsOn(PDO $pdo, FakeIdentity $identity): Invitations
{
    return new Invitations(new InvitationRepository($pdo, TestCase::container()->get(TypeRepository::class)), $identity, new FrozenClock());
}

it('lets one of two people using the same link at once make an account; the other waits, then finds it used (11)', function (): void {
    $owner = Factory::createUser(Role::Owner, 'schema-invite-owner@example.test');
    $first = new FakeIdentity();
    $second = new FakeIdentity();
    [, $token] = invitationsOn(TestCase::pdo(), $first)->invite($owner, Role::Owner, 'schema-invite-new@example.test', 'user');

    $other = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], (int) $_ENV['DB_PORT'], $_ENV['DB_NAME']),
        (string) $_ENV['DB_USER'],
        (string) $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

    // The first acceptance holds the invitation's row lock in an open transaction.
    TestCase::pdo()->beginTransaction();
    invitationsOn(TestCase::pdo(), $first)->accept($token, 'First', 'correct horse battery');

    expect(fn () => invitationsOn($other, $second)->accept($token, 'Second', 'correct horse battery'))
        ->toThrow(PDOException::class, 'Lock wait timeout');

    TestCase::pdo()->commit();

    expect(fn () => invitationsOn($other, $second)->accept($token, 'Second', 'correct horse battery'))
        ->toThrow(InvitationNotFound::class)
        ->and(array_keys($first->accountsCreated))->toBe(['schema-invite-new@example.test'])
        ->and($second->accountsCreated)->toBe([]);
});
