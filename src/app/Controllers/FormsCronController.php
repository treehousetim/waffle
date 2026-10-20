<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Ports\ClockPort;
use App\Libraries\Http\JsonResponder;
use App\Models\Forms\SubmissionRepository;
use App\Models\SettingRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Nightly: deletes form submissions older than `form_retention_days` (docs/forms.md, 11.). */
final class FormsCronController extends BaseController
{
    public function __construct(
        JsonResponder $json,
        private readonly SubmissionRepository $submissions,
        private readonly SettingRepository $settings,
        private readonly ClockPort $clock,
    ) {
        parent::__construct($json);
    }

    public function prune(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $days = max(1, (int) ($this->settings->find('form_retention_days')?->value ?? 180));
        $pruned = $this->submissions->pruneBefore($this->clock->now()->modify("-{$days} days"));

        return $this->jsonSuccess($response, ['pruned' => $pruned, 'retention_days' => $days]);
    }
}
