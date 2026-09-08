<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The worker side of an import: turn the stored payload into properties and offers.
 */
class ImportProcessor
{
    public function process(Import $import): void
    {
        // A redelivered job for an import that already finished is a no-op.
        if ($import->status->isTerminal()) {
            return;
        }

        $import->update([
            'status' => ImportStatus::Processing,
            // Reset so a retry does not double-count what a previous attempt already did.
            'processed_offers' => 0,
            'error' => null,
            'completed_at' => null,
        ]);

        foreach ($import->payload as $payload) {
            try {
                // One transaction per offer, not one for the whole import: processed_offers
                // is only meaningful if progress is durable. The cost is that a failed
                // import can leave partial data, which is safe because every write is an
                // upsert and re-running is idempotent.
                DB::transaction(fn () => $this->importOffer($import, $payload));
            } catch (Throwable $e) {
                // Fail fast. The spec says an error means `failed`, and a half-succeeded
                // import reporting `completed` would be a lie.
                $this->markFailed($import, $e, $payload['external_id'] ?? null);

                return;
            }
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Used by the Job's failed() hook, for exceptions that never reached the loop.
     */
    public function markFailed(Import $import, ?Throwable $e, ?string $externalId = null): void
    {
        $message = $e?->getMessage() ?? 'Import failed.';

        $import->update([
            'status' => ImportStatus::Failed,
            'error' => $externalId ? sprintf('offer %s: %s', $externalId, $message) : $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importOffer(Import $import, array $payload): void
    {
        $property = Property::query()->updateOrCreate(
            ['code' => $payload['property']['code']],
            [
                'name' => $payload['property']['name'],
                'city' => $payload['property']['city'],
            ]
        );

        // Upsert on (supplier_id, external_id): an offer already seen under a different
        // import is updated and repointed, never duplicated.
        Offer::query()->updateOrCreate(
            [
                'supplier_id' => $import->supplier_id,
                'external_id' => $payload['external_id'],
            ],
            [
                'property_id' => $property->id,
                'import_id' => $import->id,
                'check_in' => $payload['check_in'],
                'check_out' => $payload['check_out'],
                'max_guests' => $payload['max_guests'],
                'price' => $payload['price'],
                'currency' => Str::upper($payload['currency']),
                'available_units' => $payload['available_units'],
                'expires_at' => $payload['expires_at'],
            ]
        );

        $import->increment('processed_offers');
    }
}
