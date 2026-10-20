<?php

declare(strict_types=1);

namespace App\Libraries\Middleware;

use App\Domain\Forms\SiteKey;
use App\Libraries\Http\JsonResponder;
use App\Models\Forms\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The site API's writing routes: only a request carrying an active site's key
 * gets through, with the site as the `site` attribute (docs/forms.md, 1.).
 * The site's Caddy adds the key; a visitor never sees it.
 */
final readonly class SiteKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SiteRepository $sites,
        private JsonResponder $json,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hash = SiteKey::hash($request->getHeaderLine('X-Site-Key'));
        $site = $hash === null ? null : $this->sites->activeByKeyHash($hash);

        if ($site === null) {
            return $this->json->error('This request needs a valid site key.', 401);
        }

        return $handler->handle($request->withAttribute('site', $site));
    }
}
