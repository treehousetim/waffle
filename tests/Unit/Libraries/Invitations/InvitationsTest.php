<?php

declare(strict_types=1);

use App\Domain\Auth\Role;
use App\Domain\Invitations\InvalidInvitation;
use App\Domain\Invitations\InvitationStatus;
use App\Domain\Status\IllegalTransition;
use App\Libraries\Invitations\InvitationNotFound;
use App\Libraries\Invitations\Invitations;
use App\Models\InvitationRepository;
use Tests\Support\Factory;
use Tests\Support\Fakes\FakeIdentity;
use Tests\Support\Fakes\FrozenClock;
use Tests\TestCase;

/*
 * Invitations' rules (docs/invitations.md) on the real table, with a stand-in
 * identity so what it asks of Delight\Auth can be seen. The numbers are the
 * failure list's.
 */

beforeEach(function (): void {
    $this->clock = new FrozenClock();
    $this->identity = new FakeIdentity();
    $this->repository = TestCase::container()->get(InvitationRepository::class);
    $this->invitations = new Invitations($this->repository, $this->identity, $this->clock);
    $this->owner = Factory::createUser(Role::Owner);
});

function inviteSomeone(object $test, string $email = 'new@example.test', Role $level = Role::User): array
{
    return $test->invitations->invite($test->owner, Role::Owner, $email, $level->value);
}

it('makes an invitation with a link token shown once, stored only as its hash (3, 12)', function (): void {
    [$invitation, $token] = inviteSomeone($this, ' New@Example.TEST ', Role::Administrator);

    $stored = TestCase::pdo()->query("SELECT invitations_token_hash FROM invitations WHERE invitations_id = {$invitation->id}")->fetchColumn();

    expect($invitation->email)->toBe('new@example.test')
        ->and($invitation->role)->toBe(Role::Administrator)
        ->and($invitation->status)->toBe(InvitationStatus::Pending)
        ->and($invitation->expiresAt)->toBe('2026-01-08 12:00:00')
        ->and($stored)->toBe(hash('sha256', $token))
        ->and($invitation->toArray('2026-01-01 12:00:00'))->not->toHaveKey('token');
});

it('refuses an address that is not one, a level the inviter may not give, or an existing account (4, 5)', function (): void {
    Factory::createUser(Role::User, 'taken@example.test');

    expect(fn () => $this->invitations->invite($this->owner, Role::Owner, 'not an address', 'user'))
        ->toThrow(InvalidInvitation::class, 'Give a valid email address.')
        ->and(fn () => $this->invitations->invite($this->owner, Role::Owner, ['a'], 'user'))
        ->toThrow(InvalidInvitation::class, 'Give a valid email address.')
        ->and(fn () => $this->invitations->invite($this->owner, Role::Owner, 'a@example.test', 'owner'))
        ->toThrow(InvalidInvitation::class, 'You cannot invite someone at that level.')
        ->and(fn () => $this->invitations->invite($this->owner, Role::Administrator, 'a@example.test', 'administrator'))
        ->toThrow(InvalidInvitation::class, 'You cannot invite someone at that level.')
        ->and(fn () => $this->invitations->invite($this->owner, Role::Owner, 'a@example.test', 7))
        ->toThrow(InvalidInvitation::class, 'You cannot invite someone at that level.')
        ->and(fn () => $this->invitations->invite($this->owner, Role::Owner, 'TAKEN@example.test', 'user'))
        ->toThrow(InvalidInvitation::class, 'That address already has an account.');
});

it('revokes an earlier pending invitation for the same address, so only the newest link works (6)', function (): void {
    [$first, $firstToken] = inviteSomeone($this);
    [, $secondToken] = inviteSomeone($this);

    expect($this->repository->find($first->id)->status)->toBe(InvitationStatus::Revoked)
        ->and(fn () => $this->invitations->open($firstToken))->toThrow(InvitationNotFound::class)
        ->and($this->invitations->open($secondToken)->email)->toBe('new@example.test');
});

it('opens a link only while it is pending and unexpired, with one answer for every failure (2, 8)', function (): void {
    [, $token] = inviteSomeone($this);

    expect($this->invitations->open($token)->email)->toBe('new@example.test');

    $this->clock->advance('+7 days');

    foreach ([$token, 'not-a-token', str_repeat('A', 43)] as $link) {
        expect(fn () => $this->invitations->open($link))->toThrow(InvitationNotFound::class, 'This invitation link is not valid.');
    }

    expect($this->repository->all()[0]->toArray('2026-01-08 12:00:00')['status'])->toBe('expired');
});

it('makes the account at the invited level and uses the link up (1, 5)', function (): void {
    [$invitation, $token] = inviteSomeone($this, 'ada@example.test', Role::Administrator);

    $accepted = $this->invitations->accept($token, 'Ada Lovelace', 'correct horse battery');

    expect($accepted->status)->toBe(InvitationStatus::Accepted)
        ->and($accepted->userId)->toBeInt()
        ->and($accepted->decidedAt)->toBe('2026-01-01 12:00:00')
        ->and($this->identity->accountsCreated)->toBe(['ada@example.test' => ['password' => 'correct horse battery', 'name' => 'Ada Lovelace']])
        ->and($this->identity->rolesSet)->toBe([$accepted->userId => Role::Administrator])
        ->and(fn () => $this->invitations->accept($token, 'Ada', 'correct horse battery'))
        ->toThrow(InvitationNotFound::class, 'This invitation link is not valid.')
        ->and($invitation->id)->toBe($accepted->id);
});

it('refuses to accept an expired, revoked or malformed link, or an unusable password (2, 9)', function (): void {
    [$revoked, $revokedToken] = inviteSomeone($this, 'r@example.test');
    $this->invitations->revoke($revoked->id, Role::Owner);
    [, $expiredToken] = inviteSomeone($this, 'e@example.test');
    $this->clock->advance('+8 days');

    expect(fn () => $this->invitations->accept($revokedToken, 'R', 'correct horse battery'))->toThrow(InvitationNotFound::class)
        ->and(fn () => $this->invitations->accept($expiredToken, 'E', 'correct horse battery'))->toThrow(InvitationNotFound::class)
        ->and(fn () => $this->invitations->accept('nope', 'N', 'correct horse battery'))->toThrow(InvitationNotFound::class)
        ->and(fn () => $this->invitations->accept($expiredToken, 'E', 'short'))->toThrow(InvalidInvitation::class)
        ->and($this->identity->accountsCreated)->toBe([]);
});

it('turns an account made since the invitation into a refusal, leaving the invitation open (5)', function (): void {
    [$invitation, $token] = inviteSomeone($this, 'late@example.test');
    $this->identity->existingAccounts = ['late@example.test'];
    $invitations = $this->invitations;

    expect(fn () => $invitations->accept($token, 'Late', 'correct horse battery'))
        ->toThrow(InvalidInvitation::class, 'That address already has an account. Sign in instead.')
        ->and($this->repository->find($invitation->id)->status)->toBe(InvitationStatus::Pending);
});

it('revokes only a pending invitation, and answers not found for none (13)', function (): void {
    [$invitation] = inviteSomeone($this);
    $this->invitations->revoke($invitation->id, Role::Owner);

    expect(fn () => $this->invitations->revoke($invitation->id, Role::Owner))->toThrow(IllegalTransition::class)
        ->and(fn () => $this->invitations->revoke(999_999, Role::Owner))->toThrow(InvitationNotFound::class, 'There is no invitation with that id.');
});

it('lets an administrator revoke users\' invitations only (4)', function (): void {
    [$forAdministrator] = inviteSomeone($this, 'admin-to-be@example.test', Role::Administrator);
    [$forUser] = inviteSomeone($this, 'user-to-be@example.test', Role::User);

    expect(fn () => $this->invitations->revoke($forAdministrator->id, Role::Administrator))
        ->toThrow(InvalidInvitation::class, 'You cannot revoke an invitation at that level.')
        ->and($this->invitations->revoke($forUser->id, Role::Administrator)->status)->toBe(InvitationStatus::Revoked)
        ->and($this->repository->find($forAdministrator->id)->status)->toBe(InvitationStatus::Pending);
});
