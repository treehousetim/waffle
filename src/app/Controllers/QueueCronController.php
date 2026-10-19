<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Ports\ClockPort;
use App\Domain\Queue\QueueSettings;
use App\Libraries\Http\JsonResponder;
use App\Models\JobRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The queue's cron check, every five minutes beside /cron/health (deploy/crontab).
 *
 * Deletes finished jobs past their keeping time (15.), and answers 503 when a
 * due job has waited longer than the stall threshold: the worker has stopped
 * (16.). The crontab pings the heartbeat only when this answers 200, so a
 * stopped worker stops the heartbeat and the outside service alerts.
 */
final class QueueCronController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly JobRepository $jobs,
        private readonly ClockPort $clock,
        private readonly QueueSettings $settings,
    ) {
        parent::__construct($json);
    }

    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $now = $this->clock->now();
        $pruned = $this->jobs->pruneFinished($now->modify('-' . $this->settings->keepFinishedDays . ' days'));
        $stats = $this->jobs->stats($now);

        if ($stats['oldest_due_seconds'] > $this->settings->stallSeconds) {
            return $this->jsonError(
                $response,
                "A due job has waited {$stats['oldest_due_seconds']}s, longer than {$this->settings->stallSeconds}s: is the worker running?",
                503,
            );
        }

        return $this->jsonSuccess($response, ['stats' => $stats, 'pruned' => $pruned]);
    }
}
