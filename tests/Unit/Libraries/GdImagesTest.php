<?php

declare(strict_types=1);

use App\Domain\Content\InvalidContent;
use App\Domain\Ports\ImagePort;
use App\Libraries\Images\GdImages;
use Tests\Support\TestImages;

/* ImagePort with GD (docs/content.md, "Media"). */

it('makes a content image no larger than 2400 px, with its size, from any accepted image', function (): void {
    $wide = (new GdImages())->contentImage(TestImages::jpeg(3000, 1500));
    $small = (new GdImages())->contentImage(TestImages::png(300, 200));
    $webp = (new GdImages())->contentImage(TestImages::webp(100, 80));

    expect([$wide['width'], $wide['height']])->toBe([2400, 1200])
        ->and(getimagesizefromstring($wide['bytes'])[0])->toBe(2400)
        ->and([$small['width'], $small['height']])->toBe([300, 200])
        ->and(str_starts_with($webp['bytes'], "\xFF\xD8\xFF"))->toBeTrue()
        ->and(ImagePort::CONTENT_EDGE)->toBe(2400);
});

it('keeps nothing of the original file but its pixels: no metadata, no location (23)', function (): void {
    $original = TestImages::jpegWithMetadata('GPS-37.6872N-97.3301W');
    $image = (new GdImages())->contentImage($original)['bytes'];

    expect($original)->toContain('GPS-37.6872N-97.3301W')
        ->and($image)->not->toContain('GPS-37.6872N-97.3301W')
        ->and($image)->not->toContain('Exif');
});

it('refuses bytes that are not an image it can decode', function (): void {
    expect(fn () => (new GdImages())->contentImage('not an image'))->toThrow(InvalidContent::class, 'That image cannot be read.');
});
