<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Forms\InvalidForm;
use App\Domain\Forms\SiteStatus;
use App\Domain\Status\IllegalTransition;
use App\Libraries\Forms\FormsNotFound;
use App\Libraries\Forms\Sites;
use App\Libraries\Http\JsonResponder;
use App\Models\Forms\Site;
use App\Models\Forms\SiteRepository;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The sites that send forms, and their keys (docs/forms.md), behind `sites-manage` (10.). */
final class SiteController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly Sites $sites,
        private readonly SiteRepository $repository,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->jsonSuccess($response, ['sites' => array_map(static fn (Site $site): array => $site->toArray(), $this->repository->all())]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        return $this->act($response, fn (): array => ['site' => $this->sites->create($body['slug'] ?? null, $body['name'] ?? null, $body['origin'] ?? null)->toArray()], 201);
    }

    public function issueKey(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->act($response, function () use ($id): array {
            [$site, $key] = $this->sites->issueKey(self::id($id));

            return ['site' => $site->toArray(), 'key' => $key];
        });
    }

    public function disable(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->act($response, fn (): array => ['site' => $this->sites->setStatus(self::id($id), SiteStatus::Disabled)->toArray()]);
    }

    public function enable(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->act($response, fn (): array => ['site' => $this->sites->setStatus(self::id($id), SiteStatus::Active)->toArray()]);
    }

    /** @param Closure(): array<string,mixed> $action */
    private function act(ResponseInterface $response, Closure $action, int $status = 200): ResponseInterface
    {
        try {
            return $this->jsonSuccess($response, $action(), $status);
        } catch (FormsNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (InvalidForm $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        } catch (IllegalTransition $e) {
            return $this->jsonError($response, $e->getMessage(), 409);
        }
    }

    private static function id(string $raw): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? 0 : $id;
    }
}
