<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use App\Domain\Content\Blocks;
use App\Domain\Content\BodyFormat;
use App\Domain\Content\ChangeNotice;
use App\Domain\Content\Draft;
use App\Domain\Content\InvalidContent;
use App\Domain\Content\PublishBar;
use App\Domain\Content\PublishStatus;
use App\Domain\Content\RecordType;
use App\Domain\Content\RecordTypes;
use App\Domain\Content\Slug;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Status\IllegalTransition;
use App\Models\Content\Media;
use App\Models\Content\Record;
use App\Models\Content\RecordRepository;
use PDOException;

/**
 * Writing, publishing and restoring records of any type (docs/content.md).
 * Each change takes the record's row lock first, so two people acting on one
 * record at once take turns (14.). Every change to what the site shows queues
 * a change notice in the same transaction (19.), a minute out, so changes
 * made close together reach the site as one.
 */
final readonly class Publishing
{
    /** How long a change notice waits for more changes to join it. */
    public const string NOTICE_DELAY = '+1 minute';

    public function __construct(
        private RecordRepository $records,
        private ClockPort $clock,
        private QueuePort $queue,
        /** For record types whose blocks use images (docs/content.md, "Media"). */
        private ?MediaLibrary $media = null,
    ) {
    }

    /**
     * A new draft. Without a slug it gets one made from its title, numbered
     * past any slug ever taken.
     *
     * @param array<string,mixed> $input
     *
     * @throws InvalidContent
     */
    public function create(RecordType $type, array $input): Record
    {
        $draft = Draft::parse($type, $input, false);
        $this->media?->assertExist($this->mediaIds($type, $draft->body, $draft->fields));

        return $this->records->transactional(function () use ($type, $draft): Record {
            $slug = $draft->slug ?? $this->freeSlug($type, Slug::suggest($draft->title));

            if ($draft->slug !== null && $this->records->slugOwner($type, $slug) !== null) {
                throw self::taken($slug);
            }

            $id = $this->withSlugRace($slug, fn (): int => $this->records->insert($type, $draft, $slug, $this->clock->now()));

            return $this->mustFind($type, $id);
        });
    }

    /**
     * Replaces the working copy (3.: the site keeps the last revision). A new
     * slug is recorded and the old one kept (7.).
     *
     * @param array<string,mixed> $input
     *
     * @throws InvalidContent|ContentNotFound
     */
    public function update(RecordType $type, int $id, array $input): Record
    {
        $draft = Draft::parse($type, $input, true);
        $this->media?->assertExist($this->mediaIds($type, $draft->body, $draft->fields));

        return $this->records->transactional(function () use ($type, $id, $draft): Record {
            $record = $this->lock($type, $id);
            $now = $this->clock->now();
            $slug = (string) $draft->slug;

            if ($slug !== $record->slug) {
                $owner = $this->records->slugOwner($type, $slug);

                if ($owner !== null && $owner !== $id) {
                    throw self::taken($slug);
                }

                $this->withSlugRace($slug, fn () => $this->records->changeSlug($type, $id, $record->slug, $slug, $now));
            }

            $this->records->update($type, $id, $draft, $now);

            return $this->mustFind($type, $id);
        });
    }

    /**
     * Checks the bar (1.), makes the record published if it is not, and keeps
     * what was published as a revision, all in one transaction (4.).
     *
     * @throws InvalidContent|ContentNotFound|IllegalTransition
     */
    public function publish(RecordType $type, int $id, int $actor): Record
    {
        return $this->records->transactional(fn (): Record => $this->publishLocked($type, $this->lock($type, $id), $actor));
    }

    /** @throws ContentNotFound|IllegalTransition */
    public function retire(RecordType $type, int $id): Record
    {
        return $this->records->transactional(function () use ($type, $id): Record {
            $this->records->setStatus($type, $this->lock($type, $id), PublishStatus::Retired, $this->clock->now());
            // Every slug it ever had now answers 410 Gone.
            $this->notice($type, array_column($this->records->slugHistory($type, $id), 'slug'));

            return $this->mustFind($type, $id);
        });
    }

    /**
     * Copies one of this record's revisions into the working copy, keeping
     * the current slug, and publishes it, which writes a new revision (5.).
     *
     * @throws InvalidContent|ContentNotFound|IllegalTransition
     */
    public function restore(RecordType $type, int $id, int $revisionId, int $actor): Record
    {
        return $this->records->transactional(function () use ($type, $id, $revisionId, $actor): Record {
            $record = $this->lock($type, $id);
            $content = ($this->records->findRevision($type, $id, $revisionId) ?? throw new ContentNotFound('There is no such revision of this record.'))->content;

            $this->records->update($type, $id, new Draft(
                $record->slug,
                $content['title'],
                $content['summary'],
                $content['body'],
                $content['seo_title'],
                $content['seo_description'],
                $content['fields'],
            ), $this->clock->now());

            return $this->publishLocked($type, $this->mustFind($type, $id), $actor);
        });
    }

    /**
     * Describes an image (docs/content.md, "Media"). A public image is on
     * published pages, which change with its alt text, caption or credit:
     * each record showing it is named in a change notice, in the same
     * transaction (28.).
     *
     * @param array<string,mixed> $input
     *
     * @throws InvalidContent|ContentNotFound
     */
    public function describeImage(RecordTypes $types, int $id, array $input): Media
    {
        $media = $this->media ?? throw new \LogicException('Images need the media library.');

        return $this->records->transactional(function () use ($media, $types, $id, $input): Media {
            $described = $media->describe($id, $input);

            if ($described->publicAt !== null) {
                foreach ($types->all() as $type) {
                    $this->noticeShowing($type, $id);
                }
            }

            return $described;
        });
    }

    /**
     * Where each image is used, by working copies and by what the site shows,
     * so staff see what a change to it touches: by image id, each record by
     * type name, then id.
     *
     * @return array<int,list<array{type:string,label:string,id:int,title:string,status:string}>>
     */
    public function imageUses(RecordTypes $types): array
    {
        $uses = [];

        foreach ($types->all() as $type) {
            // What the site shows, which a working copy may since have dropped.
            $live = [];

            foreach ($this->records->published($type) as $revision) {
                $live[$revision->recordId] = $this->mediaIds($type, $revision->content['body'], $revision->content['fields']);
            }

            foreach ($this->records->all($type) as $record) {
                foreach (array_unique([...$this->mediaIds($type, $record->body, $record->fields), ...($live[$record->id] ?? [])]) as $id) {
                    $uses[$id][] = ['type' => $type->name, 'label' => $type->label, 'id' => $record->id, 'title' => $record->title, 'status' => $record->status->value];
                }
            }
        }

        foreach ($uses as &$list) {
            usort($list, static fn (array $a, array $b): int => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);
        }

        return $uses;
    }

    private function publishLocked(RecordType $type, Record $record, int $actor): Record
    {
        $images = $this->mediaIds($type, $record->body, $record->fields);
        $problems = [...PublishBar::problems($type, $record->title, $record->summary, $record->body, $record->fields), ...($this->media?->problems($images) ?? [])];

        if ($problems !== []) {
            throw InvalidContent::because('This cannot be published yet. ' . implode(' ', $problems));
        }

        $now = $this->clock->now();
        // The page at the new slug, and the one at the slug last published,
        // which becomes a redirect when they differ.
        $previous = $this->records->latestRevision($type, $record->id)?->content['slug'];
        $this->notice($type, array_values(array_unique(array_filter([$record->slug, $previous]))));

        if ($record->status === PublishStatus::Published) {
            $this->records->touchContent($type, $record->id, $now);
        } else {
            $this->records->setStatus($type, $record, PublishStatus::Published, $now);
        }

        $this->records->addRevision($type, $record->id, $record->content(), $actor, $now);
        // An image a published record uses is public from now on (docs/content.md, "Media").
        $this->media?->publish($images);

        return $this->mustFind($type, $record->id);
    }

    /**
     * The images a record uses: those its blocks name (none for Markdown),
     * and those its own fields name, such as a cover.
     *
     * @param array<string,string|int|null> $fields
     *
     * @return list<int>
     */
    private function mediaIds(RecordType $type, string $body, array $fields): array
    {
        $inBody = $type->body === BodyFormat::Blocks ? Blocks::mediaIds($type->body->present($body)) : [];

        return array_values(array_unique([...$inBody, ...array_filter($type->fields->images($fields), static fn (?int $id): bool => $id !== null)]));
    }

    /** Names, in a change notice, every published record of a type that shows an image. */
    private function noticeShowing(RecordType $type, int $mediaId): void
    {
        $slugs = [];

        foreach ($this->records->published($type) as $revision) {
            if (in_array($mediaId, $this->mediaIds($type, $revision->content['body'], $revision->content['fields']), true)) {
                $slugs[] = $revision->content['slug'];
            }
        }

        if ($slugs !== []) {
            $this->notice($type, $slugs);
        }
    }

    /** @param list<string> $slugs */
    private function notice(RecordType $type, array $slugs): void
    {
        $this->queue->enqueue(ChangeNotice::of($type->name, $slugs), ChangeNotice::type(), $this->clock->now()->modify(self::NOTICE_DELAY));
    }

    private function lock(RecordType $type, int $id): Record
    {
        return $this->records->find($type, $id, true) ?? throw new ContentNotFound("There is no {$type->label} with that id.");
    }

    private function mustFind(RecordType $type, int $id): Record
    {
        return $this->records->find($type, $id) ?? throw new \LogicException('The record just written is missing.');
    }

    private function freeSlug(RecordType $type, string $base): string
    {
        $slug = $base;

        for ($n = 2; $this->records->slugOwner($type, $slug) !== null; $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * Runs a write that adds a slug. Two requests taking the same free slug at
     * once both pass the check; the unique index refuses the second (6.).
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function withSlugRace(string $slug, callable $write): mixed
    {
        try {
            return $write();
        } catch (PDOException $e) {
            throw RecordRepository::isDuplicateKey($e) ? self::taken($slug) : $e;
        }
    }

    private static function taken(string $slug): InvalidContent
    {
        return InvalidContent::because("The slug “{$slug}” is taken: every slug a record has had stays its own.");
    }
}
