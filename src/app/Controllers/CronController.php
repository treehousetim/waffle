<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Libraries\Http\JsonResponder;
use App\Models\HealthRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Cron-triggered endpoints. Behind CronMiddleware; every job is idempotent.
 *
 * A slice with scheduled work adds its own cron controller and routes rather
 * than growing this one; this holds only the platform's own health check.
 */
final class CronController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly HealthRepository $health,
    ) {
        parent::__construct($json);
    }

    /**
     * Proves the cron path works end to end: the secret was accepted, the
     * application can reach its database, and which schema it is.
     */
    public function health(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->jsonSuccess($response, [
            'database' => 'ok',
            'migration' => $this->health->latestMigration(),
        ]);
    }
}
