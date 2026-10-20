<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Whether a state-changing request came from somewhere the app should refuse.
 *
 * Form-encoded bodies are parsed, so without a defence a cross-site form post
 * is a *simple* request — no preflight to stop it — against any endpoint the
 * session cookie can reach.
 *
 * `SessionCookie` closes most of it, and this closes the rest. Two checks, in
 * the order of how much they can be trusted:
 *
 * **`Sec-Fetch-Site`** is set by the browser itself and cannot be spoofed by
 * page script, which makes it the strongest signal available without a token.
 * Only `same-origin` is allowed, and `none`, which is a typed address or a
 * bookmark; `cross-site` and `same-site` are refused.
 *
 * **`Origin`** is the fallback for a browser that sends no fetch metadata. It is
 * compared against the request's own `Host`, so there is nothing to configure
 * and nothing to get wrong per environment.
 *
 * **Neither present means allowed**, deliberately. `curl`, the cron jobs and API
 * clients send no such headers and carry no cookies, so refusing them would
 * break every non-browser caller to defend a cookie they are not using. This is
 * a defence for the cookie-authenticated frontend, not an authentication
 * scheme — which is why it is one of two layers rather than the only one.
 */
final readonly class CrossSiteRequest
{
    /** Methods that should not change anything, and so need no defence. */
    private const array SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public static function isForbidden(
        string $method,
        ?string $secFetchSite,
        ?string $origin,
        string $host,
    ): bool {
        if (in_array(strtoupper($method), self::SAFE, true)) {
            return false;
        }

        $site = strtolower(trim((string) $secFetchSite));

        /*
         * Only the same origin, or a person typing an address. `same-site` is
         * refused: any other subdomain of the same registrable domain is the
         * same *site*, and whatever runs there could post here with the
         * visitor's session.
         */
        if ($site !== '') {
            return !in_array($site, ['same-origin', 'none'], true);
        }

        $origin = trim((string) $origin);

        if ($origin === '' || $origin === 'null') {
            return false;
        }

        return self::hostOf($origin) !== strtolower(trim($host));
    }

    /**
     * Whether an /api write carries a body a plain HTML form can send
     * (`application/x-www-form-urlencoded`, `multipart/form-data`,
     * `text/plain`). Those are the "simple" requests a browser sends to
     * another origin without asking first; the frontend only ever sends
     * JSON, so refusing them means any cross-origin write needs a preflight,
     * which the app never grants. Webhooks are outside /api and keep their forms.
     *
     * The path is percent-decoded first because the router decodes it before
     * matching: `/%61pi/auth/login` reaches the login route, so it must be
     * judged as `/api/auth/login` here too.
     */
    public static function isFormBody(string $method, string $path, string $contentType): bool
    {
        $path = rawurldecode($path);

        // A site's forms come form-encoded from a browser without script,
        // through the site's server with its key (docs/forms.md, 8.).
        if (in_array(strtoupper($method), self::SAFE, true) || !str_starts_with($path, '/api/') || str_starts_with($path, '/api/site/forms/')) {
            return false;
        }

        $type = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($type, ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'], true);
    }

    /**
     * The host and port of an origin, lowercased.
     *
     * Port included, because `https://waffle.example.com:8443` and
     * `https://waffle.example.com` are different origins and `Host` carries the port
     * too. A malformed origin yields an empty string, which cannot match a real
     * host and is therefore refused.
     */
    private static function hostOf(string $origin): string
    {
        $parts = parse_url($origin);

        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        $host = strtolower($parts['host']);

        return isset($parts['port']) ? $host . ':' . $parts['port'] : $host;
    }
}
