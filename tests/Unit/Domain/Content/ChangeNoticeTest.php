<?php

declare(strict_types=1);

use App\Domain\Content\ChangeNotice;
use App\Domain\Queue\InvalidJobPayload;
use App\Libraries\Content\ChangeNoticeHandler;
use App\Libraries\Content\NoSiteToNotify;
use Tests\Support\Fakes\FakeSiteNotifier;
use Tests\Support\Fixtures\Queue\RecordingJob;

/* Change notices (docs/content.md, 19., 20.). */

it('names record types and slugs, sorted and without repeats', function (): void {
    $notice = ChangeNotice::of('posts', ['b', 'a', 'b']);

    expect($notice->changes)->toBe(['posts' => ['a', 'b']])
        ->and(ChangeNotice::type())->toBe('site-change-notice')
        ->and(ChangeNotice::fromPayload($notice->payload())->changes)->toBe($notice->changes);
});

it('merges notices waiting together into one, slugs unioned by type (20)', function (): void {
    $merged = ChangeNotice::of('posts', ['b', 'a'])
        ->merge(ChangeNotice::of('events', ['x']))
        ->merge(ChangeNotice::of('posts', ['c', 'a']));

    expect($merged->changes)->toBe(['events' => ['x'], 'posts' => ['a', 'b', 'c']])
        ->and(ChangeNotice::of('posts', ['a'])->merge(new RecordingJob(['z'])))->toBeInstanceOf(RecordingJob::class);
});

it('refuses a stored payload it did not write', function (array $payload): void {
    expect(fn () => ChangeNotice::fromPayload($payload))->toThrow(InvalidJobPayload::class);
})->with([
    'no changes' => [[]],
    'empty changes' => [['changes' => []]],
    'a type with no slugs' => [['changes' => ['posts' => []]]],
    'slugs that are not text' => [['changes' => ['posts' => [1]]]],
    'a list instead of types' => [['changes' => [['a']]]],
]);

it('sends the notice to the site, and fails when the site cannot be told, so the queue retries', function (): void {
    $site = new FakeSiteNotifier();
    $handler = new ChangeNoticeHandler($site);
    $handler->handle(ChangeNotice::of('events', ['agents']));
    $site->down = true;

    expect($handler->jobClass())->toBe(ChangeNotice::class)
        ->and($site->sent)->toBe([['events' => ['agents']]])
        ->and(fn () => $handler->handle(ChangeNotice::of('events', ['agents'])))->toThrow(RuntimeException::class);
});

it('sends nothing with no site configured: quietly in development, as a failure in production (21)', function (): void {
    (new NoSiteToNotify(false))->notify(['posts' => ['a']]);

    expect(fn () => (new NoSiteToNotify(true))->notify(['posts' => ['a']]))
        ->toThrow(RuntimeException::class, 'No site to notify: set SITE_REPOSITORY and SITE_DISPATCH_TOKEN');
});
