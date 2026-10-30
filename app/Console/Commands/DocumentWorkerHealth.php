<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('residencies:documents-health')]
#[Description('Check the dedicated document queue heartbeat and failed jobs without exposing payloads')]
class DocumentWorkerHealth extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $heartbeat = Cache::get('residencies:documents:heartbeat');
        $fresh = is_int($heartbeat) && $heartbeat <= now()->timestamp + 5 && $heartbeat >= now()->subSeconds(180)->timestamp;
        $failed = DB::table('failed_jobs')->where('connection', 'documents')->where('queue', 'documents')->count();
        $this->line(json_encode(['status' => $fresh ? 'HEALTHY' : 'DEGRADED', 'heartbeatFresh' => $fresh, 'failedJobs' => $failed], JSON_THROW_ON_ERROR));

        return $fresh ? self::SUCCESS : self::FAILURE;
    }
}
