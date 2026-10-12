<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * The one question the health check asks of the database: are you there, and
 * is the schema the one the code expects?
 */
final readonly class HealthRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * The newest migration Phinx has recorded, or null on an empty database.
     *
     * Reading phinxlog proves the connection and the credentials, and says
     * which schema this is -- which is what somebody wants to know when a
     * deploy and its migration disagree.
     */
    public function latestMigration(): ?string
    {
        $version = $this->pdo->query('SELECT MAX(version) FROM phinxlog')->fetchColumn();

        return $version === null || $version === false ? null : (string) $version;
    }
}
