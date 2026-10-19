<?php

declare(strict_types=1);

namespace App\Libraries\Queue;

use App\Domain\Ports\ClockPort;
use App\Domain\Queue\InvalidJobPayload;
use App\Domain\Queue\QueueSettings;
use App\Domain\Queue\RunOutcome;
use App\Models\JobRepository;
use App\Models\QueuedJob;
use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * Claims jobs and runs them (docs/queue.md). One per `worker` service; the
 * entry point is src/cli/worker.php.
 *
 * Each claimed job is run by its handler, then recorded: done; failed with
 * attempts left, so retried after a growing delay (7.); or dead, after its
 * last attempt (6.) or at once when it cannot be run at all (10., 11.). Every
 * attempt leaves a row in `job_runs`.
 *
 * stop() asks the worker to finish the job in hand and start no other (14.);
 * claimed jobs it had not started go back to pending.
 */
final class Worker
{
    private bool $stopping = false;

    /** @var Closure(int): int */
    private Closure $jitter;

    /**
     * @param (Closure(int): int)|null $jitter a random whole number from 0 to its argument
     */
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly JobRegistry $registry,
        private readonly ClockPort $clock,
        private readonly QueueSettings $settings,
        private readonly string $name,
        ?Closure $jitter = null,
    ) {
        $this->jitter = $jitter ?? static fn (int $max): int => random_int(0, $max);
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function stopping(): bool
    {
        return $this->stopping;
    }

    /**
     * Claims one batch and runs it.
     *
     * @return int the jobs run
     */
    public function runOnce(): int
    {
        if ($this->stopping) {
            return 0;
        }

        $now = $this->clock->now();
        $claimed = $this->jobs->claim(
            $now,
            $now->modify('+' . $this->settings->leaseSeconds . ' seconds'),
            $this->name,
            $this->settings->batchSize,
        );
        $ran = 0;

        foreach ($claimed as $job) {
            if ($this->stopping) {
                $this->jobs->release($job);
                continue;
            }

            $this->process($job);
            ++$ran;
        }

        return $ran;
    }

    /**
     * Runs batches until stopped, until it has run $maxJobs, or until it uses
     * $maxMemoryBytes, so a long-running process stays clean: Compose starts
     * a fresh one (13.). Waits $idleSeconds between empty batches, a second at
     * a time so a stop is noticed.
     *
     * A lost database connection is not caught here: it ends the run, and the
     * entry point exits for Compose to restart the worker with a new one (13.).
     *
     * @param (Closure(int): void)|null $sleep
     * @param (Closure(): int)|null     $memory
     *
     * @return int the jobs run
     */
    public function run(
        int $maxJobs = 1000,
        int $maxMemoryBytes = 134_217_728,
        int $idleSeconds = 2,
        ?Closure $sleep = null,
        ?Closure $memory = null,
    ): int {
        $sleep ??= self::sleepFor(...);
        $memory ??= self::memoryInUse(...);
        $total = 0;

        while (!$this->stopping) {
            $ran = $this->runOnce();
            $total += $ran;

            if ($total >= $maxJobs || $memory() >= $maxMemoryBytes) {
                break;
            }

            if ($ran === 0) {
                for ($waited = 0; $waited < $idleSeconds && !$this->stopping; ++$waited) {
                    $sleep(1);
                }
            }
        }

        return $total;
    }

    public static function sleepFor(int $seconds): void
    {
        sleep($seconds);
    }

    public static function memoryInUse(): int
    {
        return memory_get_usage(true);
    }

    private function process(QueuedJob $job): void
    {
        $startedAt = $this->clock->now();

        try {
            $handler = $this->registry->handler($job->type);
            $instance = $handler->jobClass()::fromPayload($job->payload);
        } catch (UnknownJobType|InvalidJobPayload $e) {
            // 10., 11. Retrying cannot fix either: dead at once.
            $this->finish($job, RunOutcome::Dead, $e->getMessage(), $startedAt);

            return;
        }

        try {
            $handler->handle($instance);
        } catch (Throwable $e) {
            $error = self::describe($e);

            if ($job->exhausted()) {
                $this->finish($job, RunOutcome::Dead, $error, $startedAt);
            } else {
                $delay = $this->settings->retryDelaySeconds($job->attempts, ($this->jitter)($this->settings->backoffSeconds));
                $this->jobs->markRetry($job, $this->clock->now()->modify("+{$delay} seconds"), $error);
                $this->jobs->recordRun($job->id, $this->name, $job->attempts, RunOutcome::Failed, $error, $startedAt, $this->clock->now());
            }

            return;
        }

        $this->finish($job, RunOutcome::Done, null, $startedAt);
    }

    private function finish(QueuedJob $job, RunOutcome $outcome, ?string $error, DateTimeImmutable $startedAt): void
    {
        $now = $this->clock->now();

        if ($outcome === RunOutcome::Done) {
            $this->jobs->markDone($job, $now);
        } else {
            $this->jobs->markDead($job, (string) $error, $now);
        }

        $this->jobs->recordRun($job->id, $this->name, $job->attempts, $outcome, $error, $startedAt, $now);
    }

    /** What went wrong, for a person reading the admin page: class and message, not the trace. */
    private static function describe(Throwable $e): string
    {
        $message = $e::class . ': ' . $e->getMessage();

        return mb_strlen($message) > 2000 ? mb_substr($message, 0, 2000) . '…' : $message;
    }
}
