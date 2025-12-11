<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ProcessDeleteSecretFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Laravel will automatically retry the job up to this number of times.
     */
    public int $tries = 5;

    private string $fileId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $fileId)
    {
        $this->fileId = $fileId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Attempt to delete the file from Azure storage
        $deleted = Storage::disk('azure')->delete($this->fileId);

        if (! $deleted) {
            // Throwing an exception tells Laravel:
            // "Retry this job until max attempts have been reached"
            throw new RuntimeException("Failed to delete secret file {$this->fileId}");
        }

        // If deletion succeeded, job is finished.
    }
}
