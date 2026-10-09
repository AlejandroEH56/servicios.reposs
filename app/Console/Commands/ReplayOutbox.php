<?php

namespace App\Console\Commands;

use App\Modules\Shared\Infrastructure\Operations\OperationalAccess;
use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('outbox:replay {event}')]
#[Description('Replay a FAILED event using the restricted operator environment')]
class ReplayOutbox extends Command
{
    public function handle(OperationalAccess $access, OutboxProcessor $outbox): int
    {
        $owner = $access->owner();
        if (! Str::isUlid((string) $this->argument('event'))) {
            $this->error('Invalid event ID.');

            return self::FAILURE;
        }
        $outbox->replay((string) $this->argument('event'), null, $owner);
        $this->info('Replay scheduled and audited.');

        return self::SUCCESS;
    }
}
