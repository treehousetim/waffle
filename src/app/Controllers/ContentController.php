<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Content\InvalidContent;
use App\Domain\Content\PublishStatus;
use App\Domain\Content\RecordType;
use App\Domain\Content\RecordTypes;
use App\Domain\Ports\IdentityPort;
use App\Domain\Status\IllegalTransition;
use App\Libraries\Content\ContentNotFound;
use App\Libraries\Content\Publishing;
use App\Libraries\Http\JsonResponder;
use App\Models\Content\Record;
use App\Models\Content\RecordRepository;
use App\Models\Content\Revision;
use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Staff writing and publishing records of any type (docs/content.md), behind
 * `content-edit` and `content-publish` (12.).
 */
final class ContentController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly RecordTypes $types,
        private readonly RecordRepository $records,
        private readonly Publishing $publishing,
        private readonly IdentityPort $identity,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response, string $type): ResponseInterface
    {
        return $this->answer($response, function () use ($request, $type): array {
            $recordType = $this->type($type);
            $raw = (string) ($request->getQueryParams()['status'] ?? '');
            $status = $raw === '' ? null : (PublishStatus::tryFrom($raw) ?? throw InvalidContent::because('Ask for draft, published or retired records.'));

            return ['records' => array_map(
                static fn (Record $record): array => $record->toArray($recordType),
                $this->records->all($recordType, $status),
            )];
        });
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, string $type): ResponseInterface
    {
        return $this->answer($response, function () use ($request, $type): array {
            $recordType = $this->type($type);

            return $this->detail($recordType, $this->publishing->create($recordType, $this->body($request)));
        }, 201);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $type, string $id): ResponseInterface
    {
        return $this->answer($response, function () use ($type, $id): array {
            $recordType = $this->type($type);
            $record = $this->records->find($recordType, self::id($id)) ?? throw new ContentNotFound("There is no {$recordType->label} with that id.");

            return $this->detail($recordType, $record);
        });
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $type, string $id): ResponseInterface
    {
        return $this->answer($response, function () use ($request, $type, $id): array {
            $recordType = $this->type($type);

            return $this->detail($recordType, $this->publishing->update($recordType, self::id($id), $this->body($request)));
        });
    }

    public function publish(ServerRequestInterface $request, ResponseInterface $response, string $type, string $id): ResponseInterface
    {
        return $this->answer($response, function () use ($type, $id): array {
            $recordType = $this->type($type);

            return $this->detail($recordType, $this->publishing->publish($recordType, self::id($id), $this->me()));
        });
    }

    public function retire(ServerRequestInterface $request, ResponseInterface $response, string $type, string $id): ResponseInterface
    {
        return $this->answer($response, function () use ($type, $id): array {
            $recordType = $this->type($type);

            return $this->detail($recordType, $this->publishing->retire($recordType, self::id($id)));
        });
    }

    public function restore(ServerRequestInterface $request, ResponseInterface $response, string $type, string $id, string $revision): ResponseInterface
    {
        return $this->answer($response, function () use ($type, $id, $revision): array {
            $recordType = $this->type($type);

            return $this->detail($recordType, $this->publishing->restore($recordType, self::id($id), self::id($revision), $this->me()));
        });
    }

    /** @return array<string,mixed> the working copy with its revisions and slugs */
    private function detail(RecordType $type, Record $record): array
    {
        return [
            'record' => $record->toArray($type),
            'revisions' => array_map(static fn (Revision $revision): array => $revision->toArray(), $this->records->revisions($type, $record->id)),
            'slugs' => $this->records->slugHistory($type, $record->id),
        ];
    }

    /**
     * Runs one action and answers it: 404 for nothing there (13.), 422 for
     * content refused (1., 6.-10.), 409 for a move the machine refuses (2.).
     *
     * @param Closure(): array<string,mixed> $action
     */
    private function answer(ResponseInterface $response, Closure $action, int $status = 200): ResponseInterface
    {
        try {
            return $this->jsonSuccess($response, $action(), $status);
        } catch (ContentNotFound $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (InvalidContent $e) {
            return $this->jsonError($response, $e->getMessage(), 422);
        } catch (IllegalTransition $e) {
            return $this->jsonError($response, $e->getMessage(), 409);
        }
    }

    private function type(string $name): RecordType
    {
        return $this->types->find($name) ?? throw new ContentNotFound('There is no record type called that.');
    }

    private function me(): int
    {
        return (int) $this->identity->id();
    }

    private static function id(string $raw): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? 0 : $id;
    }
}
