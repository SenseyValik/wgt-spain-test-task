<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Thin wrapper: the import logic lives in ImportService.
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

    /**
     * @param  bool  $rowByRow  Take the row-by-row path instead of the bulk one. Only the
     *                          `import:generate --row-by-row` dev command sets this; the API
     *                          always dispatches the default.
     */
    public function __construct(
        public Import $import,
        public bool $rowByRow = false,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->import->id;
    }

    public function handle(ImportService $service): void
    {
        $this->rowByRow
            ? $service->createOffersFromPayloadRowByRow($this->import)
            : $service->createOffersFromPayload($this->import);
    }

    /**
     * Guarantees a terminal `failed` status even when the job dies from something the
     * service could not catch, or exhausts its tries.
     */
    public function failed(?Throwable $e): void
    {
        app(ImportService::class)->markImportAsFailed($this->import, $e);
    }
}
