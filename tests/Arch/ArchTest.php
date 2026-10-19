<?php

declare(strict_types=1);

/**
 * The layering rules, enforced rather than documented.
 *
 * Domain/ is pure business logic: no HTTP, no database, no vendor SDKs. Models/
 * is SQL and entities: no HTTP. Only Libraries/ is allowed to touch the network
 * or a vendor client, which is what makes every integration fakeable in tests.
 */

// Every third-party namespace that reaches the network or wraps a service.
// Only App\Libraries may name any of these.
$vendorSdks = [
    // Delight\Auth is final and untestable, which is why IdentityPort exists.
    'Delight\\',
    // Add every SDK or client library you take on here, the day you add it to
    // composer.json (e.g. 'GuzzleHttp\\', 'Anthropic\\', 'phpseclib3\\').
];

arch('Domain does not know the web exists')
    ->expect('App\Domain')
    ->not->toUse([
        'Slim\\',
        'Psr\Http\\',
        'PDO',
        'PDOStatement',
        'App\Controllers',
        'App\Libraries',
    ]);

arch('Domain does not reach for superglobals or the environment')
    ->expect('App\Domain')
    ->not->toUse(['getenv', 'putenv', 'session_start', 'header', 'setcookie']);

arch('Domain does not depend on a vendor SDK')
    ->expect('App\Domain')
    ->not->toUse($vendorSdks);

arch('Models are SQL and entities, not HTTP')
    ->expect('App\Models')
    ->not->toUse(['Slim\\', 'Psr\Http\\', 'App\Controllers']);

arch('Models do not depend on a vendor SDK')
    ->expect('App\Models')
    ->not->toUse($vendorSdks);

arch('Controllers do not talk to a vendor SDK directly')
    ->expect('App\Controllers')
    ->not->toUse($vendorSdks);

arch('Controllers enqueue work; they never run a job themselves (docs/queue.md, 18)')
    ->expect('App\Controllers')
    ->not->toUse(['App\Libraries\Queue\Worker', 'App\Domain\Queue\JobHandler']);

arch('Controllers all extend BaseController')
    ->expect('App\Controllers')
    ->classes()
    ->toExtend('App\Controllers\BaseController');

arch('every class declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging leftovers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit', 'eval'])
    ->not->toBeUsed();
