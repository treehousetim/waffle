<?php

declare(strict_types=1);

/**
 * Front controller. Caddy sends everything that is not a real file here.
 *
 * The session is started before the container is built so that Delight\Auth
 * attaches to an existing session rather than configuring one itself.
 */

require __DIR__ . '/../../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE && App\Domain\Auth\SessionCookie::wantedFor((string) ($_SERVER['REQUEST_URI'] ?? '/'))) {
    /*
     * The cookie's attributes, before the session exists -- the only moment
     * they can be set. A bare session_start() takes PHP's defaults: no
     * SameSite, no Secure, no HttpOnly. See Domain/Auth/SessionCookie.
     */
    session_set_cookie_params(App\Domain\Auth\SessionCookie::params(
        App\Domain\Auth\SessionCookie::overHttps($_SERVER),
    ));

    /*
     * And strict mode, which refuses a session id the server never issued.
     * Without it a fixed id planted by a link becomes the id somebody logs in
     * under, which is session fixation and needs no cross-site request at all.
     */
    ini_set('session.use_strict_mode', '1');

    // Named for the project, so another app on the same host keeps its own.
    session_name(App\Domain\Auth\SessionCookie::NAME);

    session_start();
}

/** @var Closure(array<string,mixed>): Slim\App $bootstrap */
$bootstrap = require __DIR__ . '/../config/bootstrap.php';

$bootstrap()->run();
