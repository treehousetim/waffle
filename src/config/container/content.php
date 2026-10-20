<?php

declare(strict_types=1);

/**
 * The content slice (docs/content.md): the record types this app publishes,
 * and writing, publishing and restoring them. A new record type is a
 * migration, a `record_type:<name>` definition in its slice's fragment, and
 * its RecordFields.
 */

use App\Controllers\ContentController;
use App\Controllers\SiteContentController;
use App\Domain\Content\RecordType;
use App\Domain\Content\RecordTypes;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Domain\Ports\QueuePort;
use App\Domain\Ports\SiteNotifierPort;
use App\Libraries\Clients\GitHubDispatch;
use App\Libraries\Content\ChangeNoticeHandler;
use App\Libraries\Content\NoSiteToNotify;
use App\Libraries\Content\Publishing;
use App\Libraries\Http\JsonResponder;
use App\Models\Content\RecordRepository;
use App\Models\TypeRepository;
use GuzzleHttp\Client;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    // Every slice's `record_type:<name>`, gathered in container.php.
    RecordTypes::class => factory(static fn (ContainerInterface $c): RecordTypes => new RecordTypes(...array_map(
        static fn (string $name): RecordType => $c->get('record_type:' . $name),
        $c->get('content.record_types'),
    ))),

    'record_type:posts' => factory(static fn (): RecordType => new RecordType('posts', 'post', 'post')),

    RecordRepository::class => factory(
        static fn (PDO $pdo, TypeRepository $types): RecordRepository => new RecordRepository($pdo, $types)
    ),

    Publishing::class => factory(static fn (ContainerInterface $c): Publishing => new Publishing(
        $c->get(RecordRepository::class),
        $c->get(ClockPort::class),
        $c->get(QueuePort::class),
    )),

    // Change notices (docs/content.md): a repository_dispatch to the site's
    // repository when both are set, and nothing to send otherwise.
    SiteNotifierPort::class => factory(static function (ContainerInterface $c): SiteNotifierPort {
        /** @var array{app:array{env:string},site:array{repository:string,dispatch_token:string}} $config */
        $config = $c->get('config');
        $site = $config['site'];

        return $site['repository'] !== '' && $site['dispatch_token'] !== ''
            ? new GitHubDispatch(new Client(), $site['repository'], $site['dispatch_token'])
            : new NoSiteToNotify($config['app']['env'] === 'production');
    }),

    'job:site-change-notice' => factory(
        static fn (SiteNotifierPort $site): ChangeNoticeHandler => new ChangeNoticeHandler($site)
    ),

    SiteContentController::class => factory(static fn (ContainerInterface $c): SiteContentController => new SiteContentController(
        $c->get(JsonResponder::class),
        $c->get(RecordTypes::class),
        $c->get(RecordRepository::class),
    )),

    ContentController::class => factory(static fn (ContainerInterface $c): ContentController => new ContentController(
        $c->get(JsonResponder::class),
        $c->get(RecordTypes::class),
        $c->get(RecordRepository::class),
        $c->get(Publishing::class),
        $c->get(IdentityPort::class),
    )),
];
