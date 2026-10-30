<?php

namespace App\Modules\Residencies\Infrastructure\Jobs;

use App\Modules\Residencies\Application\DocumentWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RenderResidencyDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $documentId)
    {
        $this->onConnection('documents')->onQueue('documents');
    }

    /**
     * Execute the job.
     */
    public function handle(DocumentWorkflow $documents): void
    {
        $documents->render($this->documentId);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 60];
    }
}
