<?php

declare(strict_types=1);

namespace App\Libraries\Middleware;

use App\Domain\Auth\CrossSiteRequest;
use App\Libraries\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses a state-changing request that a browser says came from another site.
 *
 * The second half of the cross-site defence; `SessionCookie`'s `SameSite=Lax`
 * is the first. Two layers because they fail differently: the cookie attribute
 * depends on the browser honouring it, and this depends on the browser telling
 * the truth about where the request came from — but the header it reads is one
 * page script cannot set.
 *
 * Registered globally (src/config/middleware.php). It refuses nothing that
 * carries neither header, so `/cron` and `/webhooks` — which authenticate with
 * a shared secret and a signature, neither of which a browser will attach on a
 * stranger's behalf, and which are called by things that send no `Origin` at
 * all — are unaffected. The form-body refusal applies to `/api` only.
 *
 * The decision itself is in `Domain/Auth/CrossSiteRequest`, which is where the
 * reasoning and the ordering of the two signals is written down.
 */
final readonly class SameSiteMiddleware implements MiddlewareInterface
{
    public function __construct(private JsonResponder $json)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // The request's own host and port, from its URI: Slim builds the Host
        // header without the port, so on a port that is not the default
        // (:8080 locally) the header never matches a browser's Origin.
        $uri = $request->getUri();
        $host = $uri->getHost() . ($uri->getPort() === null ? '' : ':' . $uri->getPort());

        $forbidden = CrossSiteRequest::isForbidden(
            $request->getMethod(),
            $request->getHeaderLine('Sec-Fetch-Site'),
            $request->getHeaderLine('Origin'),
            $host,
        );

        if ($forbidden) {
            /*
             * 403 rather than 401: the credentials were fine, the request was
             * not. A 401 would send the frontend's own client to the login
             * page, which is misleading and, for a request that did not come
             * from the frontend, pointless.
             */
            return $this->json->error('That request did not come from this site.', 403);
        }

        if (CrossSiteRequest::isFormBody(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getHeaderLine('Content-Type'),
        )) {
            return $this->json->error('Only JSON is accepted here.', 415);
        }

        return $handler->handle($request);
    }
}
