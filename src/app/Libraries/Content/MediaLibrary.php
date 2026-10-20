<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use App\Domain\Content\ImageUpload;
use App\Domain\Content\InvalidContent;
use App\Domain\Content\MediaDetails;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\ImagePort;
use App\Domain\Ports\StoragePort;
use App\Models\Content\Media;
use App\Models\Content\MediaRepository;

/**
 * The media library (docs/content.md, "Media"): images uploaded once,
 * re-encoded so nothing of the original file survives but its pixels,
 * described, and used by any record.
 */
final readonly class MediaLibrary
{
    public function __construct(
        private MediaRepository $media,
        private StoragePort $storage,
        private ImagePort $images,
        private ClockPort $clock,
    ) {
    }

    /**
     * The same picture uploaded twice is one image (25.).
     *
     * @throws InvalidContent
     */
    public function upload(string $bytes, string $contentType, int $by): Media
    {
        ImageUpload::check($bytes, $contentType);
        $image = $this->images->contentImage($bytes);
        $sha = hash('sha256', $image['bytes']);
        $existing = $this->media->findBySha($sha);

        if ($existing !== null) {
            return $existing;
        }

        $key = 'media/' . substr($sha, 0, 32) . '.jpg';
        $this->storage->put($key, $image['bytes']);
        $id = $this->media->insert($key, $sha, $image['width'], $image['height'], strlen($image['bytes']), $by, $this->clock->now());

        return $this->mustFind($id);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @throws InvalidContent|ContentNotFound
     */
    public function describe(int $id, array $input): Media
    {
        $this->mustFind($id);
        $this->media->describe($id, MediaDetails::parse($input));

        return $this->mustFind($id);
    }

    /** An image's file, for staff (any image) or for the site (only one that is public). */
    public function file(int $id, bool $publicOnly): ?string
    {
        $media = $this->media->find($id);

        return $media === null || ($publicOnly && $media->publicAt === null) ? null : $this->storage->get($media->storageKey);
    }

    /**
     * Images a record names that are not in the library (refused when it is
     * saved).
     *
     * @param list<int> $ids
     *
     * @throws InvalidContent
     */
    public function assertExist(array $ids): void
    {
        $missing = array_diff($ids, array_keys($this->media->many($ids)));

        if ($missing !== []) {
            throw InvalidContent::because('There is no image ' . implode(', ', $missing) . ' in the media library.');
        }
    }

    /**
     * Why the images a record uses keep it from being published (24.).
     *
     * @param list<int> $ids
     *
     * @return list<string>
     */
    public function problems(array $ids): array
    {
        $found = $this->media->many($ids);
        $problems = [];

        foreach ($ids as $id) {
            $problems = [...$problems, ...(isset($found[$id]) ? $found[$id]->problems() : ["Image {$id} is not in the media library."])];
        }

        return $problems;
    }

    /** @param list<int> $ids */
    public function publish(array $ids): void
    {
        $this->media->markPublic($ids, $this->clock->now());
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int,array<string,mixed>> each image as the site gets it, by id
     */
    public function forSite(array $ids): array
    {
        return array_map(static fn (Media $media): array => $media->forSite(), $this->media->many($ids));
    }

    private function mustFind(int $id): Media
    {
        return $this->media->find($id) ?? throw new ContentNotFound('There is no image with that id.');
    }
}
