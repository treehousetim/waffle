<?php

declare(strict_types=1);

namespace App\Domain\Content;

/**
 * What an uploaded image must be before it is processed (docs/content.md,
 * "Media"; 23.): at most 2 MB (Caddy's limit on any request body; the Media
 * page shrinks a picture before sending it), a JPEG, PNG or WebP image whose
 * bytes agree with the type it was sent as, and at most 40 million pixels,
 * so decoding it cannot exhaust memory.
 */
final class ImageUpload
{
    public const int MAX_BYTES = 2 * 1024 * 1024;

    public const int MAX_PIXELS = 40_000_000;

    /** @throws InvalidContent */
    public static function check(string $bytes, string $contentType): void
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));
        $size = $bytes === '' ? false : @getimagesizefromstring($bytes);

        $problem = match (true) {
            $bytes === '' => 'Send the image as the request body.',
            strlen($bytes) > self::MAX_BYTES => 'An image can be at most 2 MB.',
            !in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true) => 'An image must be a JPEG, PNG or WebP file.',
            self::detect($bytes) !== $type => 'That file is not the kind of image it says it is.',
            $size === false || $size[0] < 1 || $size[1] < 1 => 'That image cannot be read.',
            $size[0] * $size[1] > self::MAX_PIXELS => 'That image is too large; use one under 40 megapixels.',
            default => null,
        };

        if ($problem !== null) {
            throw InvalidContent::because($problem);
        }
    }

    /** The image type its bytes say it is, or null. */
    private static function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }
}
