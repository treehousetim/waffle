<?php

declare(strict_types=1);

/**
 * The mail slice (docs/mail.md): MailPort over Resend, and the job that sends
 * an email from the queue. Without RESEND_API_KEY, NoMailer: the worker's log
 * on a developer's machine, a refusal in production.
 */

use App\Domain\Mail\MailSettings;
use App\Controllers\WebhookController;
use App\Domain\Forms\EmailForm;
use App\Domain\Forms\FormDefinition;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\MailPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Ports\ReceivedMailPort;
use App\Libraries\Clients\ResendReceiving;
use App\Libraries\Http\JsonResponder;
use App\Libraries\Mail\Inbound;
use App\Libraries\Mail\InboundMailHandler;
use App\Libraries\Mail\NoReceivedMail;
use App\Models\Forms\SubmissionRepository;
use App\Models\UserRepository;
use App\Models\WebhookDeliveryRepository;
use App\Libraries\Clients\ResendMailer;
use App\Libraries\Mail\NoMailer;
use App\Libraries\Mail\SendMailHandler;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    MailSettings::class => factory(static function (ContainerInterface $c): MailSettings {
        /** @var array{mail:array{from:string,app_url:string,app_name:string}} $config */
        $config = $c->get('config');

        return new MailSettings($config['mail']['from'], $config['mail']['app_url'], $config['mail']['app_name']);
    }),

    MailPort::class => factory(static function (ContainerInterface $c): MailPort {
        /** @var array{app:array{env:string},mail:array{resend_key:string}} $config */
        $config = $c->get('config');

        return $config['mail']['resend_key'] !== ''
            ? new ResendMailer(new Client(), $config['mail']['resend_key'], $c->get(MailSettings::class)->from)
            : new NoMailer($config['app']['env'] === 'production', static function (string $line): void {
                error_log($line);
            });
    }),

    'job:send-mail' => factory(static fn (MailPort $mailer): SendMailHandler => new SendMailHandler($mailer)),

    // Mail sent to the domain: Resend's webhook, and the job that files it.
    ReceivedMailPort::class => factory(static function (ContainerInterface $c): ReceivedMailPort {
        /** @var array{mail:array{receiving_key:string}} $config */
        $config = $c->get('config');

        return $config['mail']['receiving_key'] !== ''
            ? new ResendReceiving(new Client(), $config['mail']['receiving_key'])
            : new NoReceivedMail();
    }),

    WebhookDeliveryRepository::class => factory(static fn (PDO $pdo): WebhookDeliveryRepository => new WebhookDeliveryRepository($pdo)),

    Inbound::class => factory(static function (ContainerInterface $c): Inbound {
        /** @var array{mail:array{webhook_secret:string}} $config */
        $config = $c->get('config');

        return new Inbound($config['mail']['webhook_secret'], $c->get(WebhookDeliveryRepository::class), $c->get(QueuePort::class), $c->get(ClockPort::class));
    }),

    'job:inbound-mail' => factory(static fn (ContainerInterface $c): InboundMailHandler => new InboundMailHandler(
        $c->get(ReceivedMailPort::class),
        $c->get(SubmissionRepository::class),
        $c->get(UserRepository::class),
        $c->get(QueuePort::class),
        $c->get(MailSettings::class),
        $c->get(ClockPort::class),
    )),

    // The Inbox's Email form: filed by the app, never sent by a site (15.).
    'form:email' => factory(static fn (): FormDefinition => new FormDefinition('email', 'Email', '', new EmailForm(), false)),

    WebhookController::class => factory(static fn (ContainerInterface $c): WebhookController => new WebhookController(
        $c->get(JsonResponder::class),
        $c->get(Inbound::class),
    )),
];
