<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Content\Blocks;
use App\Domain\Content\BodyFormat;
use App\Domain\Content\PublishStatus;
use App\Domain\Content\RecordType;
use App\Domain\Content\RecordTypes;
use App\Libraries\Content\MediaLibrary;
use App\Libraries\Http\JsonResponder;
use App\Models\Content\RecordRepository;
use App\Models\Content\Revision;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The site API for content (docs/content.md, "Site API"): what a Waffle Site
 * build reads to make pages. Public and read-only, like everything the site
 * shows: only published revisions leave, never a working copy (3.).
 */
final class SiteContentController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly RecordTypes $types,
        private readonly RecordRepository $records,
        private readonly MediaLibrary $media,
    ) {
        parent::__construct($json);
    }

    /** Every published record, as last published, with its old slugs to redirect. */
    public function published(ServerRequestInterface $request, ResponseInterface $response, string $type): ResponseInterface
    {
        // Routed once per declared type (src/config/routes/content.php).
        $recordType = $this->types->find($type) ?? throw new \LogicException("'{$type}' is routed but not declared.");

        $firstPublished = $this->records->firstPublished($recordType, PublishStatus::Published);
        $slugs = $this->records->slugsByRecord($recordType, PublishStatus::Published);

        return $this->jsonSuccess($response, [
            'records' => array_map(
                fn (Revision $revision): array => $this->present($recordType, $revision, $firstPublished, $slugs),
                $this->records->published($recordType),
            ),
        ]);
    }

    /** Every slug of every retired record: the site answers them 410 Gone. */
    public function retired(ServerRequestInterface $request, ResponseInterface $response, string $type): ResponseInterface
    {
        // Routed once per declared type (src/config/routes/content.php).
        $recordType = $this->types->find($type) ?? throw new \LogicException("'{$type}' is routed but not declared.");

        return $this->jsonSuccess($response, [
            'slugs' => array_merge([], ...array_values($this->records->slugsByRecord($recordType, PublishStatus::Retired))),
        ]);
    }

    /**
     * @param array<int,string>       $firstPublished
     * @param array<int,list<string>> $slugs
     *
     * @return array<string,mixed>
     */
    private function present(RecordType $type, Revision $revision, array $firstPublished, array $slugs): array
    {
        $content = $revision->content;
        $images = $type->fields->images($content['fields']);
        $media = $this->media->forSite(array_values(array_filter($images, static fn (?int $id): bool => $id !== null)));

        return [
            'id' => $revision->recordId,
            'slug' => $content['slug'],
            'title' => $content['title'],
            'summary' => $content['summary'],
            'body' => $this->siteBody($type, $content['body']),
            'seo_title' => $content['seo_title'],
            'seo_description' => $content['seo_description'],
            ...$type->fields->present($content['fields']),
            // Each image a field names, as an image block carries it (`cover`).
            ...array_map(static fn (?int $id): ?array => $id === null ? null : $media[$id] ?? null, $images),
            'published_at' => self::utc($firstPublished[$revision->recordId]),
            'updated_at' => self::utc($revision->publishedAt),
            'previous_slugs' => array_values(array_diff($slugs[$revision->recordId], [$content['slug']])),
        ];
    }

    /** A stored UTC time as the site reads it: "2026-11-12T18:30:00Z". */
    private static function utc(string $stored): string
    {
        return str_replace(' ', 'T', $stored) . 'Z';
    }

    /**
     * The body as the site gets it: a block that names images carries each
     * image's path, size and description (docs/content.md, "Media").
     *
     * @return string|list<array<string,mixed>>
     */
    private function siteBody(RecordType $type, string $stored): string|array
    {
        $body = $type->body->present($stored);

        if ($type->body !== BodyFormat::Blocks) {
            return $body;
        }

        $media = $this->media->forSite(Blocks::mediaIds($body));

        return array_map(static fn (array $block): array => match ($block['type']) {
            'image' => [...$block, 'media' => $media[$block['media_id']] ?? null],
            'gallery' => [...$block, 'media' => array_values(array_filter(array_map(static fn (int $id): ?array => $media[$id] ?? null, $block['media_ids'])))],
            default => $block,
        }, $body);
    }
}
