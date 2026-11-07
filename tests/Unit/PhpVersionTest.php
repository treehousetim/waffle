<?php

declare(strict_types=1);

/*
 * The PHP floor, 8.5.12, asserted wherever a version is chosen: the image the
 * app runs in, the build that refuses an older one, composer's platform
 * requirement (which CI's composer install checks), and the PHP running this
 * suite, which is that image's.
 */

const PHP_FLOOR = '8.5.12';

function phpVersionFile(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $name);
}

it('builds the image from an exact PHP patch at or above the floor', function (): void {
    preg_match('/^FROM php:(\d+\.\d+\.\d+)-fpm$/m', phpVersionFile('docker/php/Dockerfile'), $match);

    expect($match)->not->toBeEmpty()
        ->and(version_compare($match[1], PHP_FLOOR, '>='))->toBeTrue();
});

it('refuses to build an image older than the floor', function (): void {
    expect(phpVersionFile('docker/php/Dockerfile'))
        ->toContain('version_compare(PHP_VERSION, "' . PHP_FLOOR . '", ">=")');
});

it('requires the floor in composer.json and its lock', function (): void {
    $json = json_decode(phpVersionFile('composer.json'), true);
    $lock = json_decode(phpVersionFile('composer.lock'), true);

    expect($json['require']['php'])->toBe('^' . PHP_FLOOR)
        ->and($lock['platform']['php'])->toBe('^' . PHP_FLOOR);
});

it('runs on PHP at or above the floor', function (): void {
    expect(version_compare(PHP_VERSION, PHP_FLOOR, '>='))->toBeTrue();
});
