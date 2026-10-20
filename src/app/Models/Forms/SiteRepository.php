<?php

declare(strict_types=1);

namespace App\Models\Forms;

use App\Domain\Forms\SiteInput;
use App\Domain\Forms\SiteStatus;
use App\Models\TypeRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** The `sites` table (docs/forms.md). Statuses go through SiteStatus. */
final readonly class SiteRepository
{
    private const string SELECT = 'SELECT sites_id, sites_slug, sites_name, sites_origin, sites_key_hash, sites_key_issued_at, sites_status_id FROM sites';

    public function __construct(
        private PDO $pdo,
        private TypeRepository $types,
    ) {
    }

    /** @throws \PDOException a duplicate key when the slug is taken */
    public function insert(SiteInput $site, DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO sites (sites_slug, sites_name, sites_origin, sites_status_id, sites_created_at, sites_updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$site->slug, $site->name, $site->origin, $this->statusId(SiteStatus::Active), self::time($now), self::time($now)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Site
    {
        return $this->one(' WHERE sites_id = ?', [$id]);
    }

    /** The active site this key hash belongs to, if any (1.). */
    public function activeByKeyHash(string $hash): ?Site
    {
        return $this->one(' WHERE sites_key_hash = ? AND sites_status_id = ?', [$hash, $this->statusId(SiteStatus::Active)]);
    }

    /** @return list<Site> */
    public function all(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query(self::SELECT . ' ORDER BY sites_slug')->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function setKeyHash(int $id, string $hash, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE sites SET sites_key_hash = ?, sites_key_issued_at = ?, sites_updated_at = ? WHERE sites_id = ?')
            ->execute([$hash, self::time($now), self::time($now), $id]);
    }

    public function setStatus(Site $site, SiteStatus $next, DateTimeImmutable $now): void
    {
        $site->status->assertCanTransitionTo($next);

        $this->pdo->prepare('UPDATE sites SET sites_status_id = ?, sites_updated_at = ? WHERE sites_id = ?')
            ->execute([$this->statusId($next), self::time($now), $site->id]);
    }

    private function statusId(SiteStatus $status): int
    {
        return $this->types->id('site_status', $status->value);
    }

    private static function time(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param list<mixed> $values */
    private function one(string $where, array $values): ?Site
    {
        $statement = $this->pdo->prepare(self::SELECT . $where);
        $statement->execute($values);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Site
    {
        return new Site(
            (int) $row['sites_id'],
            (string) $row['sites_slug'],
            (string) $row['sites_name'],
            (string) $row['sites_origin'],
            $row['sites_key_hash'] === null ? null : (string) $row['sites_key_hash'],
            $row['sites_key_issued_at'] === null ? null : (string) $row['sites_key_issued_at'],
            SiteStatus::from($this->types->name((int) $row['sites_status_id'])),
        );
    }
}
