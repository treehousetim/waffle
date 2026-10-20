<?php

declare(strict_types=1);

namespace App\Libraries\Images;

use App\Domain\Content\InvalidContent;
use App\Domain\Ports\ImagePort;

/**
 * ImagePort with PHP's GD (docs/content.md, "Media"): decode, scale to fit,
 * re-encode as a JPEG. The new file holds the pixels and nothing else, so a
 * phone photo's location and every other piece of metadata is gone. Callers
 * check the upload's type, size and pixel count first (ImageUpload).
 */
final readonly class GdImages implements ImagePort
{
    public function contentImage(string $bytes): array
    {
        // Asked first: GD warns about data it cannot decode, and a warning is
        // not an answer.
        $source = getimagesizefromstring($bytes) === false ? false : imagecreatefromstring($bytes);

        if ($source === false) {
            throw InvalidContent::because('That image cannot be read.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::CONTENT_EDGE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $image = imagecreatetruecolor($newWidth, $newHeight);
        // A transparent PNG or WebP lands on white, not black.
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($image, null, 85);

        return ['bytes' => (string) ob_get_clean(), 'width' => $newWidth, 'height' => $newHeight];
    }
}
