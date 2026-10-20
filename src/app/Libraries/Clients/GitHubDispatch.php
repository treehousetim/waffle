<?php

declare(strict_types=1);

namespace App\Libraries\Clients;

use App\Domain\Ports\SiteNotifierPort;
use GuzzleHttp\Client;

/**
 * SiteNotifierPort over GitHub: a `repository_dispatch` event of type
 * `change-notice` on the site's repository, carrying the changes, which the
 * site's workflow turns into a scoped build (docs/content.md). The token
 * needs the Contents permission on that repository only.
 *
 * Network only, so excluded from coverage: keep it thin.
 */
final readonly class GitHubDispatch implements SiteNotifierPort
{
    public function __construct(
        private Client $http,
        private string $repository,
        private string $token,
    ) {
    }

    public function notify(array $changes): void
    {
        $this->http->post("https://api.github.com/repos/{$this->repository}/dispatches", [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer ' . $this->token,
                'X-GitHub-Api-Version' => '2022-11-28',
            ],
            'json' => ['event_type' => 'change-notice', 'client_payload' => ['changes' => $changes]],
            'timeout' => 20,
        ]);
    }
}
