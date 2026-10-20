<?php

declare(strict_types=1);

namespace App\Domain\Ports;

/**
 * Turns an uploaded image into the one the app keeps (docs/content.md,
 * "Media"): a JPEG re-encoded from its pixels, so nothing of the original
 * file survives but the picture, its metadata and location included. The
 * real adapter uses GD.
 */
interface ImagePort
{
    /** Content images are kept at most this long on their longer side. */
    public const int CONTENT_EDGE = 2400;

    /**
     * @return array{bytes:string,width:int,height:int}
     *
     * @throws \App\Domain\Content\InvalidContent when the image cannot be decoded
     */
    public function contentImage(string $bytes): array;
}
