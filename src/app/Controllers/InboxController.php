<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Forms\SubmissionStatus;
use App\Domain\Ports\ClockPort;
use App\Domain\Ports\IdentityPort;
use App\Domain\Status\IllegalTransition;
use App\Libraries\Http\JsonResponder;
use App\Models\Forms\Submission;
use App\Models\Forms\SubmissionRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** What the site's forms sent (docs/forms.md, "The inbox"), behind `forms-manage` (10.). */
final class InboxController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly SubmissionRepository $submissions,
        private readonly IdentityPort $identity,
        private readonly ClockPort $clock,
    ) {
        parent::__construct($json);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = SubmissionStatus::tryFrom((string) ($request->getQueryParams()['status'] ?? 'new'));

        if ($status === null) {
            return $this->jsonError($response, 'Ask for new, handled or spam submissions.', 422);
        }

        return $this->jsonSuccess($response, [
            'submissions' => array_map(static fn (Submission $submission): array => $submission->toArray(), $this->submissions->withStatus($status)),
        ]);
    }

    public function handled(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->decide($response, $id, SubmissionStatus::Handled);
    }

    public function spam(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        return $this->decide($response, $id, SubmissionStatus::Spam);
    }

    private function decide(ResponseInterface $response, string $id, SubmissionStatus $next): ResponseInterface
    {
        $number = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $submission = $number === false ? null : $this->submissions->find($number);

        if ($submission === null) {
            return $this->jsonError($response, 'There is no submission with that id.', 404);
        }

        try {
            $this->submissions->decide($submission, $next, (int) $this->identity->id(), $this->clock->now());
        } catch (IllegalTransition) {
            return $this->jsonError($response, 'Only a new submission can be marked.', 409);
        }

        return $this->jsonSuccess($response, ['submission' => $this->submissions->find($submission->id)?->toArray()]);
    }
}
