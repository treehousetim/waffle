<?php

declare(strict_types=1);

/**
 * The content slice's routes (docs/content.md). `{type}` is a record type's
 * name; the controller answers 404 for any other.
 */

use App\Controllers\ContentController;
use App\Controllers\MediaController;
use App\Controllers\SiteContentController;
use App\Domain\Auth\Permission;
use App\Domain\Content\RecordTypes;
use App\Libraries\Middleware\AuthMiddleware;
use App\Libraries\Middleware\Deferred;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, ContainerInterface $container): void {
    $can = static fn (Permission $permission): Deferred => new Deferred($container, 'can:' . $permission->value);

    // The media library (docs/content.md, "Media"): before /api/content/{type},
    // so "media" is never read as a record type.
    $app->group('/api/content/media', function (RouteCollectorProxy $media) use ($can): void {
        $media->get('', [MediaController::class, 'index'])->add($can(Permission::ContentEdit));
        $media->post('', [MediaController::class, 'upload'])->add($can(Permission::ContentEdit));
        $media->put('/{id}', [MediaController::class, 'describe'])->add($can(Permission::ContentEdit));
        $media->get('/{id}/file', [MediaController::class, 'file'])->add($can(Permission::ContentEdit));
    })->add(AuthMiddleware::class);
    $app->get('/api/site/media/{id:[0-9]+}.jpg', [MediaController::class, 'siteFile']);

    $app->group('/api/content/{type}', function (RouteCollectorProxy $content) use ($can): void {
        $content->get('', [ContentController::class, 'index'])->add($can(Permission::ContentEdit));
        $content->post('', [ContentController::class, 'create'])->add($can(Permission::ContentEdit));
        $content->get('/{id}', [ContentController::class, 'show'])->add($can(Permission::ContentEdit));
        $content->put('/{id}', [ContentController::class, 'update'])->add($can(Permission::ContentEdit));
        $content->post('/{id}/publish', [ContentController::class, 'publish'])->add($can(Permission::ContentPublish));
        $content->post('/{id}/retire', [ContentController::class, 'retire'])->add($can(Permission::ContentPublish));
        $content->post('/{id}/revisions/{revision}/restore', [ContentController::class, 'restore'])->add($can(Permission::ContentPublish));
    })->add(AuthMiddleware::class);

    // The site API: public and read-only, published revisions only. One pair
    // of paths per declared type, so the site API never matches a name it
    // does not publish.
    foreach ($container->get(RecordTypes::class)->all() as $type) {
        $app->get("/api/site/{$type->name}", [SiteContentController::class, 'published'])->setArgument('type', $type->name);
        $app->get("/api/site/{$type->name}/retired", [SiteContentController::class, 'retired'])->setArgument('type', $type->name);
    }
};
