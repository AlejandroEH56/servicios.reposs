<?php

namespace App\Console\Commands;

use App\Modules\Shared\Infrastructure\Outbox\OutboxMetrics as Metrics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outbox:metrics {--check}')]
#[Description('Report aggregate outbox metrics and optionally enforce operational thresholds')]
class OutboxMetrics extends Command
{
    public function handle(Metrics $metrics): int
    {
        $snapshot = $metrics->snapshot();
        $this->line(json_encode($snapshot, JSON_THROW_ON_ERROR));

        return $this->option('check') && ($snapshot['failed'] > 0 || $snapshot['oldest_eligible_seconds'] > 60
            || $snapshot['heartbeat_age_seconds'] > config('modernization.health.outbox_heartbeat_ttl')) ? self::FAILURE : self::SUCCESS;
    }
}
