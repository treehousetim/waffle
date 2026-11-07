<?php

declare(strict_types=1);

use App\Domain\Ports\QueuePort;
use App\Domain\Queue\Job;
use App\Libraries\Mail\Inbound;
use App\Models\WebhookDeliveryRepository;
use Tests\Support\Fakes\FakeQueue;
use Tests\Support\Fakes\FrozenClock;
use Tests\Support\Svix;
use Tests\TestCase;

/*
 * A webhook delivery and its job together, or neither (docs/mail.md, 2., 14.),
 * on a connection that commits. Not transaction-wrapped: it cleans up after
 * itself.
 */

function forgetDeliveries(): void
{
    TestCase::pdo()->exec("DELETE FROM webhook_deliveries WHERE webhook_deliveries_delivery_id LIKE 'schema-msg-%'");
}

beforeEach(fn () => forgetDeliveries());
afterEach(fn () => forgetDeliveries());

function deliveredOnce(QueuePort $queue, string $id): void
{
    $clock = new FrozenClock();
    $body = '{"type":"email.received","data":{"email_id":"em_schema"}}';
    $at = $clock->now()->getTimestamp();

    (new Inbound(Svix::SECRET, new WebhookDeliveryRepository(TestCase::pdo()), $queue, $clock))
        ->receive($id, (string) $at, Svix::sign(Svix::SECRET, $id, $at, $body), $body);
}

function deliveryKept(string $id): bool
{
    $statement = TestCase::pdo()->prepare('SELECT 1 FROM webhook_deliveries WHERE webhook_deliveries_delivery_id = ?');
    $statement->execute([$id]);

    return $statement->fetchColumn() !== false;
}

it('keeps the delivery with its job, and keeps neither when the job cannot be queued (2, 14)', function (): void {
    $failing = new class () implements QueuePort {
        public function enqueue(Job $job, ?string $uniqueKey = null, ?DateTimeImmutable $runAfter = null): void
        {
            throw new RuntimeException('The queue is down.');
        }
    };

    deliveredOnce(new FakeQueue(), 'schema-msg-kept');

    expect(deliveryKept('schema-msg-kept'))->toBeTrue()
        ->and(fn () => deliveredOnce($failing, 'schema-msg-lost'))->toThrow(RuntimeException::class, 'The queue is down.')
        ->and(deliveryKept('schema-msg-lost'))->toBeFalse();
});

it('reports a database error that is not a repeat, rather than taking it for one', function (): void {
    $deliveries = new WebhookDeliveryRepository(TestCase::pdo());

    expect(fn () => $deliveries->record(str_repeat('p', 60), 'schema-msg-long', 'x', '{}', new DateTimeImmutable()))->toThrow(PDOException::class);
});
