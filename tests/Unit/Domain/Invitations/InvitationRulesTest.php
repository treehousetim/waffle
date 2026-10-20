<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Domain\Invitations\InvalidInvitation;
use App\Domain\Invitations\InvitationRules;
use App\Domain\Invitations\InvitationStatus;
use App\Domain\Invitations\InvitationToken;
use App\Domain\Invitations\NewAccount;
use App\Domain\Status\IllegalTransition;

/* The invitations slice's rules (docs/invitations.md). The numbers are the failure list's. */

it('moves an invitation once, from pending to accepted or revoked (1, 13)', function (): void {
    expect(InvitationStatus::Pending->allowedNext())->toBe([InvitationStatus::Accepted, InvitationStatus::Revoked])
        ->and(InvitationStatus::Accepted->isTerminal())->toBeTrue()
        ->and(InvitationStatus::Revoked->isTerminal())->toBeTrue()
        ->and(fn () => InvitationStatus::Accepted->assertCanTransitionTo(InvitationStatus::Revoked))
        ->toThrow(IllegalTransition::class, "invitation cannot go from 'accepted' to 'revoked'.");
});

it('issues a long random token, and keeps only its hash (3)', function (): void {
    [$token, $hash] = InvitationToken::issue();
    [$other] = InvitationToken::issue();

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($token)->not->toBe($other)
        ->and($hash)->toBe(hash('sha256', $token))
        ->and(InvitationToken::hash($token))->toBe($hash)
        ->and(InvitationToken::hash('short'))->toBeNull()
        ->and(InvitationToken::hash($token . '='))->toBeNull()
        ->and(InvitationToken::hash(str_repeat('/', 43)))->toBeNull();
});

it('lets an owner invite administrators and users, an administrator users, and nobody an owner (4)', function (): void {
    expect(InvitationRules::levelsFor(Role::Owner))->toBe([Role::Administrator, Role::User])
        ->and(InvitationRules::levelsFor(Role::Administrator))->toBe([Role::User])
        ->and(InvitationRules::levelsFor(Role::User))->toBe([])
        ->and(InvitationRules::levelsFor(null))->toBe([])
        ->and(InvitationRules::mayInvite(Role::Owner, Role::Owner))->toBeFalse()
        ->and(InvitationRules::mayInvite(Role::Administrator, Role::Administrator))->toBeFalse()
        ->and(InvitationRules::mayInvite(Role::Administrator, Role::User))->toBeTrue();
});

it('takes a name and a password of at least 12 characters (9)', function (): void {
    $account = NewAccount::parse('  Ada Lovelace ', 'correct horse battery');

    expect([$account->name, $account->password])->toBe(['Ada Lovelace', 'correct horse battery']);
});

it('refuses a missing or unusable name or password (9)', function (mixed $name, mixed $password, string $message): void {
    expect(fn () => NewAccount::parse($name, $password))->toThrow(InvalidInvitation::class, $message);
})->with([
    'no name' => ['  ', 'correct horse battery', 'Give your name'],
    'a long name' => [str_repeat('a', 101), 'correct horse battery', 'Give your name'],
    'a line break' => ["Ada\nLovelace", 'correct horse battery', 'Give your name'],
    'not UTF-8' => ["\xC3\x28", 'correct horse battery', 'Give your name'],
    'not text' => [['Ada'], 'correct horse battery', 'Give your name'],
    'a short password' => ['Ada', 'eleven char', 'Choose a password of at least 12 characters.'],
    'a huge password' => ['Ada', str_repeat('a', 1001), 'Choose a password of at least 12 characters.'],
    'no password' => ['Ada', null, 'Choose a password of at least 12 characters.'],
]);
