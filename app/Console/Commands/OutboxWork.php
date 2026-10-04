<?php

namespace App\Console\Commands;

use App\Modules\Shared\Infrastructure\Outbox\OutboxProcessor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('outbox:work {--once : Process one batch}')]
#[Description('Publish transactional outbox messages to registered idempotent database consumers')]
class OutboxWork extends Command
{
    public function handle(OutboxProcessor $processor): int
    {
        do {
            try {
                $processor->runOnce();
            } catch (Throwable) {
                $this->error('OUTBOX_UNAVAILABLE');

                return self::FAILURE;
            }
            if (! $this->option('once')) {
                sleep(2);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
