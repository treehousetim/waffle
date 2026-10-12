<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * The attributes the session cookie carries.
 *
 * A bare `session_start()` takes PHP's defaults: no `SameSite`, no `Secure`,
 * no `HttpOnly`, and no strict session-id mode. That leaves the only defence to
 * the browser — Chrome's Lax-by-default is a vendor policy, not a control this
 * application asserts, and it is not universal.
 *
 * Pure, so the values are testable without a request: `src/public/index.php`
 * asks for them and hands them to `session_set_cookie_params()` before the
 * session starts, which is the only moment they can be set.
 */
final readonly class SessionCookie
{
    /**
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     *
     * @param bool $overHttps whether this request arrived over TLS. Production
     *        Caddy passes `HTTPS=on` to PHP over TLS; local development is plain
     *        HTTP on a loopback port, and a `Secure` cookie there would simply
     *        never be sent back — a login that silently does nothing.
     */
    public static function params(bool $overHttps): array
    {
        return [
            // A session cookie: gone when the browser closes, rather than a
            // dated one that outlives the tab it was issued to.
            'lifetime' => 0,
            'path' => '/',
            // Host-only. An empty domain means the cookie is not offered to
            // subdomains, which matters on a zone that also serves anything
            // else: a cookie for the whole domain hands the session to every
            // other application on it.
            'domain' => '',
            'secure' => $overHttps,
            // No script needs to read it. Delight\Auth reads it server-side.
            'httponly' => true,
            /*
             * Lax rather than Strict. Lax already refuses the attack in the
             * main attack — a cross-site form post carries no cookie — while
             * letting an ordinary link into the app arrive signed in. Strict
             * would log somebody out of every link in a chat message, which is
             * how a security control gets turned off.
             */
            'samesite' => 'Lax',
        ];
    }

    /**
     * Whether this request arrived over TLS, from the CGI environment.
     *
     * Reads `HTTPS`, which Caddy's php_fastcgi sets on a TLS request as a FastCGI
     * parameter, and falls back to the forwarded-proto header for the case
     * where something else terminates TLS. Not a PSR-7 request, because this is
     * answered before the container — and therefore Slim — exists.
     *
     * @param array<string,mixed> $server typically `$_SERVER`
     */
    public static function overHttps(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';

        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $forwarded = $server['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($forwarded) && strtolower($forwarded) === 'https';
    }
}
