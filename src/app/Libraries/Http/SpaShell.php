<?php

declare(strict_types=1);

namespace App\Libraries\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Serves the built Vite shell for SPA deep links.
 *
 * Nginx sends every non-file request to index.php, so a GET for /settings
 * arrives here as a Slim 404 and is answered with index.html. The shell must
 * never be cached — Vite hashes the asset filenames it references, so a stale
 * shell points at assets that no longer exist.
 */
final readonly class SpaShell
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private string $indexPath,
    ) {
    }

    public function isBuilt(): bool
    {
        return is_file($this->indexPath);
    }

    public function response(): ResponseInterface
    {
        if (!$this->isBuilt()) {
            $response = $this->responseFactory->createResponse(503);
            $response->getBody()->write(
                'The frontend has not been built. Run: cd src/frontend && npm ci && npm run build'
            );

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $response = $this->responseFactory->createResponse(200);
        $response->getBody()->write((string) file_get_contents($this->indexPath));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
