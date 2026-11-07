<?php

declare(strict_types=1);

namespace App\Libraries\Mail;

use App\Domain\Mail\InboundMail;
use App\Domain\Mail\InvalidSignature;
use App\Domain\Mail\SvixSignature;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\QueuePort;
use App\Models\WebhookDeliveryRepository;

/**
 * Resend's webhook for mail sent to the domain (docs/mail.md, "Mail sent to
 * the domain"): checks the signature (13.), records the delivery so one sent
 * again does nothing (14.), and queues the message's id to be fetched and
 * filed by the worker. Other events are recorded and left alone.
 */
final readonly class Inbound
{
    public function __construct(
        private string $secret,
        private WebhookDeliveryRepository $deliveries,
        private QueuePort $queue,
        private ClockPort $clock,
    ) {
    }

    /** @throws InvalidSignature */
    public function receive(string $id, string $timestamp, string $signature, string $body): void
    {
        $now = $this->clock->now();
        SvixSignature::verify($this->secret, $id, $timestamp, $signature, $body, $now->getTimestamp());

        $event = json_decode($body, true);
        $kind = is_array($event) && is_string($event['type'] ?? null) ? $event['type'] : 'unreadable';
        $emailId = is_array($event) ? ($event['data']['email_id'] ?? null) : null;

        $this->deliveries->transactional(function () use ($id, $kind, $body, $now, $emailId): void {
            if (!$this->deliveries->record('resend', $id, $kind, $body, $now)) {
                return;
            }

            if ($kind === 'email.received' && is_string($emailId) && InboundMail::isId($emailId)) {
                $this->queue->enqueue(InboundMail::of($emailId));
            }
        });
    }
}
