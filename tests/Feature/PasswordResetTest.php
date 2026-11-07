<?php

declare(strict_types=1);

use App\Domain\Auth\PasswordResetMail;
use App\Domain\Auth\Role;
use App\Domain\Ports\MailPort;
use App\Domain\Ports\QueuePort;
use Tests\Support\Factory;
use Tests\TestCase;

/*
 * Password reset through the router, with the real Delight\Auth
 * (docs/password-reset.md). The numbers are the failure list's.
 */

/** Runs the queued reset emails, and returns the token last emailed to the address. */
function emailedResetToken(string $email): string
{
    $container = TestCase::container();
    $handler = $container->get('job:password-reset-mail');

    foreach ($container->get(QueuePort::class)->enqueued as $queued) {
        if ($queued['job'] instanceof PasswordResetMail) {
            $handler->handle($queued['job']);
        }
    }

    foreach (array_reverse($container->get(MailPort::class)->sent) as $sent) {
        if ($sent['message']->to === [$email] && preg_match('#/reset/([A-Za-z0-9_-]{43})#', $sent['message']->text, $match) === 1) {
            return $match[1];
        }
    }

    return '';
}

it('answers a request the same way, account or not (1)', function (): void {
    Factory::createUser(Role::User, 'has-account@example.test');

    $known = $this->post('/api/auth/password/forgot', ['email' => 'has-account@example.test']);
    $unknown = $this->post('/api/auth/password/forgot', ['email' => 'no-account@example.test']);
    $malformed = $this->post('/api/auth/password/forgot', ['email' => 'nope']);

    expect($known->getStatusCode())->toBe(200)
        ->and($unknown->getStatusCode())->toBe(200)
        ->and($this->json($known)['data'])->toBe($this->json($unknown)['data'])
        ->and($this->json($known)['data']['message'])->toBe('If that address has an account, we have emailed it a link.')
        ->and($malformed->getStatusCode())->toBe(422);
});

it('sets a new password from the emailed link, which then signs in, once (2, 6)', function (): void {
    Factory::createUser(Role::User, 'reset-me@example.test');
    $this->post('/api/auth/password/forgot', ['email' => 'reset-me@example.test']);
    $token = emailedResetToken('reset-me@example.test');

    $opened = $this->get("/api/password-reset/{$token}");
    $weak = $this->post("/api/password-reset/{$token}", ['password' => 'short']);
    $set = $this->post("/api/password-reset/{$token}", ['password' => 'a brand new passphrase']);
    $again = $this->post("/api/password-reset/{$token}", ['password' => 'another new passphrase']);
    $login = $this->post('/api/auth/login', ['email' => 'reset-me@example.test', 'password' => 'a brand new passphrase']);

    expect($opened->getStatusCode())->toBe(200)
        ->and($this->json($opened)['data'])->toBe(['email' => 'reset-me@example.test'])
        ->and($weak->getStatusCode())->toBe(422)
        ->and($set->getStatusCode())->toBe(200)
        ->and($again->getStatusCode())->toBe(404)
        ->and($this->json($again)['error'])->toBe('This link is not valid. Ask for a new one.')
        ->and($login->getStatusCode())->toBe(200);
});

it('answers every unusable link with the same 404 (2)', function (): void {
    foreach (['short', str_repeat('A', 43)] as $token) {
        expect($this->get("/api/password-reset/{$token}")->getStatusCode())->toBe(404)
            ->and($this->post("/api/password-reset/{$token}", ['password' => 'a brand new passphrase'])->getStatusCode())->toBe(404);
    }
});
