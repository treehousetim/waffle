<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Ports\ClockPort;
use App\Libraries\Http\JsonResponder;
use App\Models\JobRepository;
use App\Models\QueuedJob;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The queue, for the people who look after it (docs/queue.md, failure 20):
 * how much is waiting, and the dead jobs, each of which they may retry or
 * discard. Behind `queue-manage`.
 */
final class QueueController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly JobRepository $jobs,
        private readonly ClockPort $clock,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->jsonSuccess($response, [
            'stats' => $this->jobs->stats($this->clock->now()),
            'dead' => array_map($this->listed(...), $this->jobs->dead()),
        ]);
    }

    /** The route's {id} arrives by name, through the PHP-DI bridge. */
    public function retry(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        if (!$this->jobs->retryDead(self::id($id), $this->clock->now())) {
            return $this->jsonError($response, 'There is no failed job with that id.', 404);
        }

        return $this->jsonSuccess($response, ['retried' => true]);
    }

    public function discard(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        if (!$this->jobs->discardDead(self::id($id))) {
            return $this->jsonError($response, 'There is no failed job with that id.', 404);
        }

        return $this->jsonSuccess($response, ['discarded' => true]);
    }

    /** @return array<string,mixed> */
    private function listed(QueuedJob $job): array
    {
        return [
            'id' => $job->id,
            'type' => $job->type,
            'attempts' => $job->attempts,
            'error' => $job->lastError,
            'created_at' => $job->createdAt,
            'failed_at' => $job->finishedAt,
            'runs' => $this->jobs->runs($job->id),
        ];
    }

    /** A positive whole number, or 0, which matches no job. */
    private static function id(string $raw): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? 0 : $id;
    }
}
