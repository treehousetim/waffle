<?php

declare(strict_types=1);

use App\Domain\Content\Blocks;
use App\Domain\Content\ImageUpload;
use App\Domain\Content\InvalidContent;
use App\Domain\Content\Licence;
use App\Domain\Content\MediaDetails;
use Tests\Support\TestImages;

/* The media library's rules (docs/content.md, "Media"). The numbers are the failure list's. */

it('takes a JPEG, PNG or WebP under 2 MB and 40 megapixels (23)', function (string $bytes, string $type): void {
    ImageUpload::check($bytes, $type);

    expect(true)->toBeTrue();
})->with([
    'a JPEG' => [TestImages::jpeg(), 'image/jpeg'],
    'a PNG' => [TestImages::png(), 'image/png; charset=binary'],
    'a WebP' => [TestImages::webp(), 'image/webp'],
]);

it('refuses anything else (23)', function (string $bytes, string $type, string $message): void {
    expect(fn () => ImageUpload::check($bytes, $type))->toThrow(InvalidContent::class, $message);
})->with([
    'nothing' => ['', 'image/jpeg', 'Send the image as the request body.'],
    'too big' => [str_repeat('x', ImageUpload::MAX_BYTES + 1), 'image/jpeg', 'An image can be at most 2 MB.'],
    'a GIF' => ['GIF89a....', 'image/gif', 'An image must be a JPEG, PNG or WebP file.'],
    'a PNG sent as a JPEG' => [TestImages::png(), 'image/jpeg', 'That file is not the kind of image it says it is.'],
    'a broken JPEG' => ["\xFF\xD8\xFF" . 'not really', 'image/jpeg', 'That image cannot be read.'],
    'a huge PNG' => [TestImages::hugePngHeader(), 'image/png', 'That image is too large'],
]);

it('reads an image\'s details, and says what it still needs to be published (24)', function (): void {
    $details = MediaDetails::parse(['alt' => ' A room of people at laptops ', 'caption' => '', 'credit_name' => 'Ada', 'credit_url' => 'https://example.com/ada', 'licence' => 'licensed']);

    expect([$details->alt, $details->caption, $details->creditName, $details->creditUrl, $details->licence])
        ->toBe(['A room of people at laptops', null, 'Ada', 'https://example.com/ada', Licence::Licensed])
        ->and(MediaDetails::parse([])->licence)->toBe(Licence::Own)
        ->and(MediaDetails::problems(3, '', Licence::Licensed, null))->toBe(['Image 3 needs a description (alt text).', 'Image 3 needs a credit.'])
        ->and(MediaDetails::problems(3, 'A room', Licence::Own, null))->toBe([])
        ->and(Licence::Permission->needsCredit())->toBeTrue()
        ->and(fn () => MediaDetails::parse(['licence' => 'stolen']))->toThrow(InvalidContent::class, 'The licence must be own, permission or licensed.')
        ->and(fn () => MediaDetails::parse(['credit_url' => 'http://example.com']))->toThrow(InvalidContent::class, 'The credit link must be an https:// address.')
        ->and(fn () => MediaDetails::parse(['alt' => str_repeat('a', 301)]))->toThrow(InvalidContent::class);
});

it('takes image and gallery blocks by media id, and names every image a body uses once (22)', function (): void {
    $blocks = Blocks::check([
        ['type' => 'image', 'media_id' => 7, 'caption' => 'The room'],
        ['type' => 'gallery', 'media_ids' => [8, 7]],
        ['type' => 'heading', 'level' => 2, 'text' => 'After'],
    ]);

    expect($blocks[0])->toBe(['type' => 'image', 'media_id' => 7, 'caption' => 'The room'])
        ->and($blocks[1])->toBe(['type' => 'gallery', 'media_ids' => [8, 7], 'caption' => null])
        ->and(Blocks::mediaIds($blocks))->toBe([7, 8]);
});

it('refuses an image block without an image, and a gallery of one (22)', function (array $block, string $message): void {
    expect(fn () => Blocks::check([$block]))->toThrow(InvalidContent::class, $message);
})->with([
    'no image' => [['type' => 'image'], 'Block 1 (image): it needs an image from the media library.'],
    'a name instead of an id' => [['type' => 'image', 'media_id' => 'cat.jpg'], 'Block 1 (image): it needs an image'],
    'a gallery of one' => [['type' => 'gallery', 'media_ids' => [1]], 'Block 1 (gallery): a gallery needs 2 to 24 images.'],
    'a gallery of nonsense' => [['type' => 'gallery', 'media_ids' => [1, -2]], 'Block 1 (gallery): it needs an image'],
]);
