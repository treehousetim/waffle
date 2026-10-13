<?php

declare(strict_types=1);

namespace ModuleTool;

/**
 * One HTTPS request, no redirects followed: Download decides about those.
 * Clients/HttpsTransport is the only code here that opens a connection.
 */
interface Transport
{
    /**
     * @return array{status:int,location:?string,body:string} at most $maxBytes of body
     */
    public function get(string $url, int $maxBytes): array;
}
