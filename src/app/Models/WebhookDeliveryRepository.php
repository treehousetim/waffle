<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;

/**
 * One row per webhook delivery (AGENTS.md §4, "Integrations"): its provider,
 * its own id, its kind and a hash of its body, so a delivery sent again is
 * seen and does nothing (docs/mail.md, 14.).
 */
final readonly class WebhookDeliveryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Records a delivery; false when it was recorded before. */
    public function record(string $provider, string $deliveryId, string $kind, string $body, DateTimeImmutable $now): bool
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO webhook_deliveries (webhook_deliveries_provider, webhook_deliveries_delivery_id, webhook_deliveries_kind,'
                . ' webhook_deliveries_payload_hash, webhook_deliveries_received_at) VALUES (?, ?, ?, ?, ?)'
            )->execute([$provider, mb_substr($deliveryId, 0, 100), mb_substr($kind, 0, 60), hash('sha256', $body), $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }

            throw $e;
        }

        return true;
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
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }
}
