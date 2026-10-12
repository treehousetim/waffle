<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * The `types` lookup table: status and type names to their ids.
 *
 * Shared through the container and memoised for the life of the request — no
 * other class builds its own instance, so the whole table is read at most once
 * per request no matter how many repositories need it.
 *
 * Every status and type column is a foreign key into `types`, so an unknown name
 * here means the enum and the migrations have drifted apart. That is a bug, not
 * a user error, and it throws.
 */
final class TypeRepository
{
    /** @var array<string,array<string,int>>|null group => name => id */
    private ?array $byGroup = null;

    /** @var array<int,array{group:string,name:string,label:string}>|null */
    private ?array $byId = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function id(string $group, string $name): int
    {
        $this->load();

        $id = $this->byGroup[$group][$name] ?? null;

        if ($id === null) {
            throw new RuntimeException(
                "Unknown type '{$name}' in group '{$group}'. Add it in a migration before using it."
            );
        }

        return $id;
    }

    public function name(int $id): string
    {
        $this->load();

        $row = $this->byId[$id] ?? null;

        if ($row === null) {
            throw new RuntimeException("Unknown type id {$id}.");
        }

        return $row['name'];
    }

    public function label(int $id): string
    {
        $this->load();

        $row = $this->byId[$id] ?? null;

        if ($row === null) {
            throw new RuntimeException("Unknown type id {$id}.");
        }

        return $row['label'];
    }

    /**
     * Every name in a group, in sort order, for rendering a select.
     *
     * @return array<string,string> name => label
     */
    public function options(string $group): array
    {
        $statement = $this->pdo->prepare(
            'SELECT types_name, types_label FROM types WHERE types_group = ? ORDER BY types_sort ASC, types_name ASC'
        );
        $statement->execute([$group]);

        /** @var list<array{types_name:string,types_label:string}> $rows */
        $rows = $statement->fetchAll();

        return array_column($rows, 'types_label', 'types_name');
    }

    /**
     * Drops the memoised table. Only the test suite needs this — the rows are
     * reference data and never change inside a request.
     */
    public function forget(): void
    {
        $this->byGroup = null;
        $this->byId = null;
    }

    private function load(): void
    {
        if ($this->byGroup !== null) {
            return;
        }

        /** @var list<array{types_id:int,types_group:string,types_name:string,types_label:string}> $rows */
        $rows = $this->pdo->query('SELECT types_id, types_group, types_name, types_label FROM types')->fetchAll();

        $byGroup = [];
        $byId = [];

        foreach ($rows as $row) {
            $byGroup[$row['types_group']][$row['types_name']] = (int) $row['types_id'];
            $byId[(int) $row['types_id']] = [
                'group' => $row['types_group'],
                'name' => $row['types_name'],
                'label' => $row['types_label'],
            ];
        }

        $this->byGroup = $byGroup;
        $this->byId = $byId;
    }
}
