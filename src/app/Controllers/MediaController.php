<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Content\InvalidContent;
use App\Domain\Content\RecordTypes;
use App\Domain\Ports\IdentityPort;
use App\Libraries\Content\ContentNotFound;
use App\Libraries\Content\MediaLibrary;
use App\Libraries\Content\Publishing;
use App\Libraries\Http\JsonResponder;
use App\Models\Content\Media;
use App\Models\Content\MediaRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The media library for staff (docs/content.md, "Media"), behind
 * `content-edit`; and an image's file for the site, once it is public.
 */
final class MediaController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly MediaLibrary $library,
        private readonly MediaRepository $media,
        private readonly IdentityPort $identity,
        private readonly Publishing $publishing,
        private readonly RecordTypes $types,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->jsonSuccess($response, ['media' => array_map(static fn (Media $media): array => $media->toArray(), $this->media->all())]);
    }

    /** The image is the request body (Content-Type image/jpeg, image/png or image/webp). */
    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $media = $this->library->upload((string) $request->getBody(), $request->getHeaderLine('Content-Type'), (int) $this->identity->id());
        } catch (InvalidContent $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        }

        return $this->jsonSuccess($response, ['media' => $media->toArray()], 201);
    }

    /** A public image's new description reaches the site through a change notice. */
    public function describe(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        try {
            $media = $this->publishing->describeImage($this->types, self::id($id), $this->body($request));
        } catch (ContentNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (InvalidContent $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        }

        return $this->jsonSuccess($response, ['media' => $media->toArray()]);
    }

    /** For staff: any image in the library. */
    public function file(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->image($response, $this->library->file(self::id($id), false), 'private, no-store');
    }

    /** For the site's build: only an image a published record has used (26.). */
    public function siteFile(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->image($response, $this->library->file(self::id($id), true), 'public, max-age=86400');
    }

    private function image(ResponseInterface $response, ?string $bytes, string $cache): ResponseInterface
    {
        if ($bytes === null) {
            return $this->jsonError($response, 'There is no such image.', 404);
        }

        $response->getBody()->write($bytes);

        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', $cache);
    }

    private static function id(string $raw): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? 0 : $id;
    }
}
