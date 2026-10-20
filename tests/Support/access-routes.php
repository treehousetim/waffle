<?php

declare(strict_types=1);

use App\Domain\Auth\Permission;
use Tests\TestCase;

/*
 * The route table AccessGridTest walks (docs/access-levels.md): what every
 * /api route needs, written apart from the route files on purpose. A route
 * added without a line here fails AccessGridTest, so none can ship unguarded
 * by accident.
 */

/** Routes open to anybody, or to any signed-in session: not guarded by a level. */
const UNGUARDED = [
    'POST /api/auth/login',
    'POST /api/auth/logout',
    // Any signed-in session may ask who it is, whatever its level.
    'GET /api/auth/me',
    // The site API: public, read-only, published content only (docs/content.md).
    'GET /api/site/posts',
    'GET /api/site/posts/retired',
    'GET /api/site/pages',
    'GET /api/site/pages/retired',
    // An invitation link: the token is the permission (docs/invitations.md).
    'GET /api/invite/{token}',
    'POST /api/invite/{token}/accept',
    // A site's form: guarded by the site key, not a level (docs/forms.md).
    'POST /api/site/forms/{name}',
];

/** @return array<string,Permission> "METHOD pattern" => permission */
function guardedRoutes(): array
{
    return [
        'GET /api/hello' => Permission::Home,
        'GET /api/settings' => Permission::SettingsView,
        'PUT /api/settings' => Permission::SettingsEdit,
        'GET /api/queue' => Permission::QueueManage,
        'POST /api/queue/jobs/{id}/retry' => Permission::QueueManage,
        'DELETE /api/queue/jobs/{id}' => Permission::QueueManage,
        'GET /api/forms/submissions' => Permission::FormsManage,
        'POST /api/forms/submissions/{id}/handled' => Permission::FormsManage,
        'POST /api/forms/submissions/{id}/spam' => Permission::FormsManage,
        'GET /api/sites' => Permission::SitesManage,
        'POST /api/sites' => Permission::SitesManage,
        'POST /api/sites/{id}/key' => Permission::SitesManage,
        'POST /api/sites/{id}/disable' => Permission::SitesManage,
        'POST /api/sites/{id}/enable' => Permission::SitesManage,
        'GET /api/invitations' => Permission::InvitationsManage,
        'POST /api/invitations' => Permission::InvitationsManage,
        'POST /api/invitations/{id}/revoke' => Permission::InvitationsManage,
        'GET /api/content/{type}' => Permission::ContentEdit,
        'POST /api/content/{type}' => Permission::ContentEdit,
        'GET /api/content/{type}/{id}' => Permission::ContentEdit,
        'PUT /api/content/{type}/{id}' => Permission::ContentEdit,
        'POST /api/content/{type}/{id}/publish' => Permission::ContentPublish,
        'POST /api/content/{type}/{id}/retire' => Permission::ContentPublish,
        'POST /api/content/{type}/{id}/revisions/{revision}/restore' => Permission::ContentPublish,
    ];
}

/**
 * Every registered route under /api, as "METHOD pattern", sorted.
 *
 * @return list<string>
 */
function registeredApiRoutes(): array
{
    $routes = [];

    foreach (TestCase::app()->getRouteCollector()->getRoutes() as $route) {
        if (!str_starts_with($route->getPattern(), '/api/')) {
            continue;
        }

        foreach ($route->getMethods() as $method) {
            $routes[] = $method . ' ' . $route->getPattern();
        }
    }

    sort($routes);

    return $routes;
}

/** A pattern with every placeholder filled, so it can be requested. */
function concrete(string $pattern): string
{
    return (string) preg_replace('/\{[^}]+\}/', '1', $pattern);
}
