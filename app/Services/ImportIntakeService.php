<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * The HTTP side of an import: record it and queue it, idempotently, fast.
 *
 * Separate from ImportProcessor because the two run in different processes with different
 * failure modes — this one must return inside a request, that one is slow and retryable.
 */
class ImportIntakeService
{
    public function accept(array $validated): Import
    {
        $supplier = Supplier::query()->where('code', $validated['supplier'])->firstOrFail();

        // firstOrCreate is already race-safe in Laravel: it falls through to
        // createOrFirst(), which wraps the insert in a savepoint when inside a transaction
        // and re-reads the row if a concurrent request won the unique index.
        $import = Import::query()->firstOrCreate(
            [
                'supplier_id' => $supplier->id,
                'external_import_id' => $validated['external_import_id'],
            ],
            [
                'sent_at' => Carbon::parse($validated['sent_at']),
                'status' => ImportStatus::Pending,
                'payload' => $validated['offers'],
                'total_offers' => count($validated['offers']),
                'processed_offers' => 0,
            ]
        );

        // Only a genuinely new import is queued. A resend returns the existing row and
        // dispatches nothing, which is what "must not re-trigger processing" means.
        if ($import->wasRecentlyCreated) {
            // afterCommit, or a fast worker can pick the job up and query for an import id
            // that has not committed yet.
            ProcessImportJob::dispatch($import)->afterCommit();
        }

        return $import;
    }
}
