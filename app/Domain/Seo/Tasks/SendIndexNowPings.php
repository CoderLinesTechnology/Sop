<?php

namespace App\Domain\Seo\Tasks;

use App\Domain\Seo\IndexNow;

/**
 * Heartbeat task: sends IndexNow notifications that could not go out right
 * after the change (service unavailable, rate limited) or that waited for a
 * scheduled article to go live.
 */
class SendIndexNowPings
{
    public function __construct(private readonly IndexNow $indexNow) {}

    public function __invoke(): void
    {
        $this->indexNow->sendDue();
    }
}
