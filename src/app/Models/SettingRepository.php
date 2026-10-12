<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Settings\SettingType;
use PDO;

/**
 * The `settings` key/value table.
 *
 * Rows carry their own presentation metadata (label, description, type,
 * group, editable, sort) so the Settings page renders itself from the data,
 * and the save allowlist is `settings_editable` rather than a list in code.
 * Adding a setting is therefore a migration, not a code change.
 */
final readonly class SettingRepository
{
    private const string SELECT = 'SELECT settings_key, settings_value, settings_label, settings_description,'
        . ' settings_type_id, settings_options, settings_group, settings_editable, settings_sort,'
        . ' settings_updated_at FROM settings';

    public function __construct(
        private PDO $pdo,
        private TypeRepository $types,
    ) {
    }

    /**
     * @return list<Setting> by group, then by each group's own order
     */
    public function all(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query(self::SELECT . ' ORDER BY settings_group ASC, settings_sort ASC, settings_key ASC')
            ->fetchAll();

        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $key): ?Setting
    {
        $statement = $this->pdo->prepare(self::SELECT . ' WHERE settings_key = ?');
        $statement->execute([$key]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Stores an already-validated value. Validation is the caller's: the type
     * decides what is valid, and the controller decides what is editable.
     */
    public function update(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE settings SET settings_value = ?, settings_updated_at = UTC_TIMESTAMP() WHERE settings_key = ?'
        );
        $statement->execute([$value, $key]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Setting
    {
        $options = $row['settings_options'] === null
            ? []
            : json_decode((string) $row['settings_options'], true, 4, JSON_THROW_ON_ERROR);

        return new Setting(
            (string) $row['settings_key'],
            (string) ($row['settings_value'] ?? ''),
            (string) $row['settings_label'],
            (string) $row['settings_description'],
            SettingType::from($this->types->name((int) $row['settings_type_id'])),
            array_values(array_map('strval', (array) $options)),
            (string) $row['settings_group'],
            (bool) $row['settings_editable'],
            (int) $row['settings_sort'],
            $row['settings_updated_at'] === null ? null : (string) $row['settings_updated_at'],
        );
    }
}
