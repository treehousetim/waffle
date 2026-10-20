<?php

declare(strict_types=1);

namespace App\Libraries\Content;

use App\Domain\Content\ChangeNotice;
use App\Domain\Ports\SiteNotifierPort;
use App\Domain\Queue\Job;
use App\Domain\Queue\JobHandler;

/** Sends a change notice to the site (docs/content.md). */
final readonly class ChangeNoticeHandler implements JobHandler
{
    public function __construct(private SiteNotifierPort $site)
    {
    }

    public function jobClass(): string
    {
        return ChangeNotice::class;
    }

    public function handle(Job $job): void
    {
        assert($job instanceof ChangeNotice);

        $this->site->notify($job->changes);
    }
}
