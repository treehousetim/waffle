<?php

declare(strict_types=1);

namespace App\Models\Content;

use App\Domain\Content\Draft;
use App\Domain\Content\PublishStatus;
use App\Domain\Content\RecordType;
use App\Models\TypeRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/**
 * Any record type's three tables (docs/content.md): the records, their
 * revisions and their slugs. Every table and column name comes from the
 * RecordType, which is declared in code (15.); every value is bound.
 */
final readonly class RecordRepository
{
    private const array COLUMNS = ['id', 'slug', 'status_id', 'title', 'summary', 'body', 'seo_title', 'seo_description',
        'published_at', 'retired_at', 'content_changed_at', 'created_at', 'updated_at'];

    public function __construct(
        private PDO $pdo,
        private TypeRepository $types,
    ) {
    }

    public function find(RecordType $type, int $id, bool $forUpdate = false): ?Record
    {
        $statement = $this->pdo->prepare($this->select($type) . " WHERE {$type->column('id')} = ?" . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute([$id]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($type, $row);
    }

    /** @return list<Record> the most recently changed first */
    public function all(RecordType $type, ?PublishStatus $status = null): array
    {
        $where = $status === null ? '' : " WHERE {$type->column('status_id')} = ?";
        $statement = $this->pdo->prepare($this->select($type) . $where
            . " ORDER BY {$type->column('updated_at')} DESC, {$type->column('id')} DESC");
        $statement->execute($status === null ? [] : [$this->statusId($status)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(fn (array $row): Record => $this->hydrate($type, $row), $rows);
    }

    /** A new draft, and its slug's first history row. */
    public function insert(RecordType $type, Draft $draft, string $slug, DateTimeImmutable $now): int
    {
        $values = [
            'slug' => $slug,
            'status_id' => $this->statusId(PublishStatus::Draft),
            ...$this->draftValues($draft),
            'content_changed_at' => self::time($now),
            'created_at' => self::time($now),
            'updated_at' => self::time($now),
        ];
        $columns = implode(', ', array_map($type->column(...), array_keys($values)));
        $marks = implode(', ', array_fill(0, count($values), '?'));

        $this->pdo->prepare("INSERT INTO {$type->name} ({$columns}) VALUES ({$marks})")->execute(array_values($values));
        $id = (int) $this->pdo->lastInsertId();
        $this->addSlug($type, $id, $slug, $now);

        return $id;
    }

    /** Replaces the working copy, all but its slug and status. */
    public function update(RecordType $type, int $id, Draft $draft, DateTimeImmutable $now): void
    {
        $values = [...$this->draftValues($draft), 'updated_at' => self::time($now)];
        $sets = implode(', ', array_map(static fn (string $name): string => $type->column($name) . ' = ?', array_keys($values)));

        $this->pdo->prepare("UPDATE {$type->name} SET {$sets} WHERE {$type->column('id')} = ?")
            ->execute([...array_values($values), $id]);
    }

    /** Moves a record along an allowed edge (2.), keeping when it was first published (11.). */
    public function setStatus(RecordType $type, Record $record, PublishStatus $next, DateTimeImmutable $now): void
    {
        $record->status->assertCanTransitionTo($next);
        $at = self::time($now);
        $published = $type->column('published_at');
        $retired = $type->column('retired_at');
        [$stamps, $values] = $next === PublishStatus::Published
            ? ["{$published} = COALESCE({$published}, ?), {$retired} = NULL", [$at]]
            : ["{$retired} = ?", [$at]];

        $this->pdo->prepare(
            "UPDATE {$type->name} SET {$type->column('status_id')} = ?, {$stamps},"
            . " {$type->column('content_changed_at')} = ?, {$type->column('updated_at')} = ? WHERE {$type->column('id')} = ?"
        )->execute([$this->statusId($next), ...$values, $at, $at, $record->id]);
    }

    /** Marks a change to what the site shows, for a publish that keeps the status. */
    public function touchContent(RecordType $type, int $id, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            "UPDATE {$type->name} SET {$type->column('content_changed_at')} = ?, {$type->column('updated_at')} = ? WHERE {$type->column('id')} = ?"
        )->execute([self::time($now), self::time($now), $id]);
    }

    /** The record that has or ever had this slug, if any (6.). */
    public function slugOwner(RecordType $type, string $slug): ?int
    {
        $history = $type->slugs();
        $statement = $this->pdo->prepare("SELECT {$type->owner($history)} FROM {$history} WHERE {$history}_slug = ?");
        $statement->execute([$slug]);
        $owner = $statement->fetchColumn();

        return $owner === false ? null : (int) $owner;
    }

    /**
     * Makes `$to` the record's slug (7.): a new history row, or its own old
     * row taken back, and the old slug stamped replaced, in the caller's
     * transaction.
     *
     * @throws PDOException a duplicate key when another record took the slug first
     */
    public function changeSlug(RecordType $type, int $id, string $from, string $to, DateTimeImmutable $now): void
    {
        $history = $type->slugs();
        $owner = $type->owner($history);

        if ($this->slugOwner($type, $to) === $id) {
            $this->pdo->prepare("UPDATE {$history} SET {$history}_replaced_at = NULL WHERE {$owner} = ? AND {$history}_slug = ?")
                ->execute([$id, $to]);
        } else {
            $this->addSlug($type, $id, $to, $now);
        }

        $this->pdo->prepare("UPDATE {$history} SET {$history}_replaced_at = ? WHERE {$owner} = ? AND {$history}_slug = ?")
            ->execute([self::time($now), $id, $from]);
        $this->pdo->prepare("UPDATE {$type->name} SET {$type->column('slug')} = ?, {$type->column('updated_at')} = ? WHERE {$type->column('id')} = ?")
            ->execute([$to, self::time($now), $id]);
    }

    /**
     * Every slug the record has had, the current one first.
     *
     * @return list<array{slug:string,created_at:string,replaced_at:?string}>
     */
    public function slugHistory(RecordType $type, int $id): array
    {
        $history = $type->slugs();
        $statement = $this->pdo->prepare(
            "SELECT {$history}_slug AS slug, {$history}_created_at AS created_at, {$history}_replaced_at AS replaced_at"
            . " FROM {$history} WHERE {$type->owner($history)} = ?"
            . " ORDER BY {$history}_replaced_at IS NULL DESC, {$history}_replaced_at DESC, {$history}_id DESC"
        );
        $statement->execute([$id]);

        /** @var list<array{slug:string,created_at:string,replaced_at:?string}> */
        return $statement->fetchAll();
    }

    /** @param array<string,mixed> $content */
    public function addRevision(RecordType $type, int $id, array $content, int $publishedBy, DateTimeImmutable $now): int
    {
        $revisions = $type->revisions();

        $this->pdo->prepare(
            "INSERT INTO {$revisions} ({$type->owner($revisions)}, {$revisions}_content, {$revisions}_published_by, {$revisions}_published_at)"
            . ' VALUES (?, ?, ?, ?)'
        )->execute([$id, json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $publishedBy, self::time($now)]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<Revision> the newest first */
    public function revisions(RecordType $type, int $id): array
    {
        $statement = $this->pdo->prepare($this->selectRevisions($type) . " WHERE {$type->owner($type->revisions())} = ? ORDER BY {$type->revisions()}_id DESC");
        $statement->execute([$id]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(self::revision(...), $rows);
    }

    /** One of this record's revisions; another record's is not found (5.). */
    public function findRevision(RecordType $type, int $id, int $revisionId): ?Revision
    {
        $statement = $this->pdo->prepare($this->selectRevisions($type) . " WHERE {$type->owner($type->revisions())} = ? AND {$type->revisions()}_id = ?");
        $statement->execute([$id, $revisionId]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : self::revision($row);
    }

    public function latestRevision(RecordType $type, int $id): ?Revision
    {
        return $this->revisions($type, $id)[0] ?? null;
    }

    /**
     * What the site gets (docs/content.md, 3.): every published record's
     * latest revision, oldest first published first.
     *
     * @return list<Revision>
     */
    public function published(RecordType $type): array
    {
        $r = $type->revisions();
        $statement = $this->pdo->prepare($this->selectRevisions($type)
            . " JOIN {$type->name} ON {$type->column('id')} = {$type->owner($r)}"
            . " WHERE {$type->column('status_id')} = ?"
            . " AND {$r}_id = (SELECT MAX(latest.{$r}_id) FROM {$r} latest WHERE latest.{$type->owner($r)} = {$type->column('id')})"
            . " ORDER BY {$type->column('published_at')} ASC, {$type->column('id')} ASC");
        $statement->execute([$this->statusId(PublishStatus::Published)]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll();

        return array_map(self::revision(...), $rows);
    }

    /**
     * When each record of a status was first published, by id.
     *
     * @return array<int,string>
     */
    public function firstPublished(RecordType $type, PublishStatus $status): array
    {
        $statement = $this->pdo->prepare("SELECT {$type->column('id')}, {$type->column('published_at')} FROM {$type->name}"
            . " WHERE {$type->column('status_id')} = ? AND {$type->column('published_at')} IS NOT NULL");
        $statement->execute([$this->statusId($status)]);

        /** @var array<int,string> */
        return $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * Every slug of every record of a status, by record id: what the site
     * turns into redirects (old slugs of live records) or 410s (retired).
     *
     * @return array<int,list<string>>
     */
    public function slugsByRecord(RecordType $type, PublishStatus $status): array
    {
        $s = $type->slugs();
        $statement = $this->pdo->prepare("SELECT {$type->owner($s)} AS record_id, {$s}_slug AS slug FROM {$s}"
            . " JOIN {$type->name} ON {$type->column('id')} = {$type->owner($s)}"
            . " WHERE {$type->column('status_id')} = ? ORDER BY {$s}_id ASC");
        $statement->execute([$this->statusId($status)]);
        $slugs = [];

        foreach ($statement->fetchAll() as $row) {
            $slugs[(int) $row['record_id']][] = (string) $row['slug'];
        }

        return $slugs;
    }

    public function transactional(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public static function isDuplicateKey(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062;
    }

    private function addSlug(RecordType $type, int $id, string $slug, DateTimeImmutable $now): void
    {
        $history = $type->slugs();

        $this->pdo->prepare("INSERT INTO {$history} ({$type->owner($history)}, {$history}_slug, {$history}_created_at) VALUES (?, ?, ?)")
            ->execute([$id, $slug, self::time($now)]);
    }

    /** @return array<string,string|int|null> column name (without the table) => value */
    private function draftValues(Draft $draft): array
    {
        return [
            'title' => $draft->title,
            'summary' => $draft->summary,
            'body' => $draft->body,
            'seo_title' => $draft->seoTitle,
            'seo_description' => $draft->seoDescription,
            ...$draft->fields,
        ];
    }

    private function select(RecordType $type): string
    {
        $columns = array_map($type->column(...), [...self::COLUMNS, ...$type->fields->names()]);

        return 'SELECT ' . implode(', ', $columns) . " FROM {$type->name}";
    }

    private function selectRevisions(RecordType $type): string
    {
        $r = $type->revisions();

        return "SELECT {$r}_id AS id, {$type->owner($r)} AS record_id, {$r}_content AS content, {$r}_published_by AS published_by,"
            . " {$r}_published_at AS published_at FROM {$r}";
    }

    private function statusId(PublishStatus $status): int
    {
        return $this->types->id('publish_status', $status->value);
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(RecordType $type, array $row): Record
    {
        $value = static fn (string $name): mixed => $row[$type->column($name)];
        $optional = static fn (string $name): ?string => $value($name) === null ? null : (string) $value($name);
        $names = $type->fields->names();
        /** @var array<string,string|int|null> $fields */
        $fields = array_combine($names, array_map($value, $names));

        return new Record(
            (int) $value('id'),
            (string) $value('slug'),
            PublishStatus::from($this->types->name((int) $value('status_id'))),
            (string) $value('title'),
            (string) $value('summary'),
            (string) $value('body'),
            $optional('seo_title'),
            $optional('seo_description'),
            $fields,
            $optional('published_at'),
            $optional('retired_at'),
            (string) $value('content_changed_at'),
            (string) $value('created_at'),
            (string) $value('updated_at'),
        );
    }

    /** @param array<string,mixed> $row */
    private static function revision(array $row): Revision
    {
        /** @var array{slug:string,title:string,summary:string,body:string,seo_title:?string,seo_description:?string,fields:array<string,string|int|null>} $content */
        $content = json_decode((string) $row['content'], true, 512, JSON_THROW_ON_ERROR);

        return new Revision((int) $row['id'], (int) $row['record_id'], $content, (int) $row['published_by'], (string) $row['published_at']);
    }
}
