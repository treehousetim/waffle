<?php

declare(strict_types=1);

namespace Tests\Support;

/** Images made on the spot, for the image tests (docs/content.md, "Media"). */
final class TestImages
{
    public static function jpeg(int $width = 800, int $height = 600): string
    {
        return self::encode($width, $height, static fn ($image) => imagejpeg($image, null, 90));
    }

    public static function png(int $width = 300, int $height = 200): string
    {
        return self::encode($width, $height, static fn ($image) => imagepng($image));
    }

    public static function webp(int $width = 300, int $height = 200): string
    {
        return self::encode($width, $height, static fn ($image) => imagewebp($image));
    }

    /**
     * A JPEG carrying an Exif segment with a recognisable marker, as a phone
     * photo carries where it was taken (10.).
     */
    public static function jpegWithMetadata(string $marker = 'GPS-37.6872N-97.3301W'): string
    {
        $jpeg = self::jpeg(640, 480);
        $payload = "Exif\0\0" . $marker;
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        // Straight after the start-of-image marker, where Exif lives.
        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    /** A PNG whose header claims a size no server should decode (11.). */
    public static function hugePngHeader(int $width = 10_000, int $height = 10_000): string
    {
        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n" . self::chunk('IHDR', $ihdr) . self::chunk('IDAT', '') . self::chunk('IEND', '');
    }

    /** @param callable(\GdImage): bool $write */
    private static function encode(int $width, int $height, callable $write): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 120, 40));
        ob_start();
        $write($image);

        return (string) ob_get_clean();
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
