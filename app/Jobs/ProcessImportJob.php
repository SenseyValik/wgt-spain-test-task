<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\ImportProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Thin wrapper: the import logic lives in ImportProcessor.
 *
 * The job payload is just the import id — the offers ride in the imports.payload jsonb
 * column, not in Redis.
 */
class ProcessImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Release the uniqueness lock after an hour even if the worker dies holding it. */
    public int $uniqueFor = 3600;

    public function __construct(public Import $import) {}

    public function uniqueId(): string
    {
        return (string) $this->import->id;
    }

    public function handle(ImportProcessor $processor): void
    {
        $processor->process($this->import);
    }

    /**
     * Guarantees a terminal `failed` status even when the job dies from something the
     * processor could not catch, or exhausts its tries.
     */
    public function failed(?Throwable $e): void
    {
        app(ImportProcessor::class)->markFailed($this->import, $e);
    }
}
