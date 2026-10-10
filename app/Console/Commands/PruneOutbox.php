<?php

namespace App\Console\Commands;

use App\Modules\Shared\Infrastructure\Operations\OperationalAccess;
use App\Modules\Shared\Infrastructure\Outbox\OutboxRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outbox:prune {--limit=500}')]
#[Description('Prune published events and expired orphan inbox in bounded audited batches')]
class PruneOutbox extends Command
{
    public function handle(OperationalAccess $access, OutboxRetention $retention): int
    {
        $result = $retention->prune((int) $this->option('limit'), $access->owner());
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
