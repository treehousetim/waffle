<?php

declare(strict_types=1);

namespace App\Models\Content;

use App\Domain\Content\Licence;
use App\Domain\Content\MediaDetails;
use App\Models\TypeRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** The `media` table (docs/content.md, "Media"). */
final readonly class MediaRepository
{
    private const string SELECT = 'SELECT media_id, media_storage_key, media_sha256, media_width, media_height, media_bytes, media_alt, media_caption,'
        . ' media_credit_name, media_credit_url, media_licence_id, media_public_at, media_created_at FROM media';

    public function __construct(
        private PDO $pdo,
        private TypeRepository $types,
    ) {
    }

    public function insert(string $storageKey, string $sha256, int $width, int $height, int $bytes, int $createdBy, DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO media (media_storage_key, media_sha256, media_mime, media_width, media_height, media_bytes, media_licence_id,'
            . ' media_created_by, media_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$storageKey, $sha256, 'image/jpeg', $width, $height, $bytes, $this->licenceId(Licence::Own), $createdBy, self::time($now)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Media
    {
        return $this->many([$id])[$id] ?? null;
    }

    public function findBySha(string $sha256): ?Media
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE media_sha256 = ?');
        $statement->execute([$sha256]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int,Media> by id; ids with no image are missing
     */
    public function many(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $statement = $this->pdo->prepare(self::SELECT . ' WHERE media_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')');
        $statement->execute(array_values($ids));
        $found = [];

        foreach ($statement->fetchAll() as $row) {
            $media = $this->hydrate($row);
            $found[$media->id] = $media;
        }

        return $found;
    }

    /** @return list<Media> the newest first */
    public function all(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query(self::SELECT . ' ORDER BY media_id DESC LIMIT 500')->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function describe(int $id, MediaDetails $details): void
    {
        $this->pdo->prepare(
            'UPDATE media SET media_alt = ?, media_caption = ?, media_credit_name = ?, media_credit_url = ?, media_licence_id = ? WHERE media_id = ?'
        )->execute([$details->alt, $details->caption, $details->creditName, $details->creditUrl, $this->licenceId($details->licence), $id]);
    }

    /** @param list<int> $ids */
    public function markPublic(array $ids, DateTimeImmutable $now): void
    {
        if ($ids === []) {
            return;
        }

        $this->pdo->prepare('UPDATE media SET media_public_at = COALESCE(media_public_at, ?) WHERE media_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')')
            ->execute([self::time($now), ...$ids]);
    }

    private function licenceId(Licence $licence): int
    {
        return $this->types->id('licence', $licence->value);
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Media
    {
        return new Media(
            (int) $row['media_id'],
            (string) $row['media_storage_key'],
            (string) $row['media_sha256'],
            (int) $row['media_width'],
            (int) $row['media_height'],
            (int) $row['media_bytes'],
            (string) $row['media_alt'],
            $row['media_caption'] === null ? null : (string) $row['media_caption'],
            $row['media_credit_name'] === null ? null : (string) $row['media_credit_name'],
            $row['media_credit_url'] === null ? null : (string) $row['media_credit_url'],
            Licence::from($this->types->name((int) $row['media_licence_id'])),
            $row['media_public_at'] === null ? null : (string) $row['media_public_at'],
            (string) $row['media_created_at'],
        );
    }
}
