<?php

namespace App\Services;

use App\Enums\ImportStatus;
use App\Exceptions\WGTSpainException;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Both halves of an import: createAndQueueImport() runs inside the request,
 * createOffersFromPayload() inside the queue worker. They are here together because they
 * share the same idempotency rules.
 */
class ImportService
{
    /**
     * Offers per slice. This bounds peak memory — only one slice of bind parameters exists
     * at a time — and is capped below by what a single statement can carry.
     */
    public const CHUNK = 5000;

    /** Postgres caps a single statement at 65535 bind parameters. */
    private const MAX_BIND_PARAMS = 65535;

    /** Bind parameters per offer row in the VALUES list. */
    private const OFFER_PARAMS = 9;

    /** Bind parameters per property row in the VALUES list. */
    private const PROPERTY_PARAMS = 3;

    /**
     * Record an import and queue it, idempotently and fast. No offer is touched here —
     * the endpoint has to answer 202 immediately.
     *
     * @param  array<string, mixed>  $validated
     */
    public function createAndQueueImport(array $validated): Import
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

    /**
     * Turn the stored payload into properties and offers: slice it, and for each slice make
     * one pass that fills both statements, then write the properties and the offers.
     *
     * One transaction per slice, not one for the whole import. That is what makes
     * `processed_offers` mean something while the job is still running: Postgres shows a
     * poller nothing until COMMIT, so progress written inside a single import-wide transaction
     * would stay invisible until the very end. The counter is bumped inside the same
     * transaction as the rows it counts, so it can never claim more than was written.
     *
     * The cost is that a failure leaves the slices already committed in place. That is safe
     * because every write is an upsert, so re-running the import repairs it.
     */
    public function createOffersFromPayload(Import $import): void
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

        // Read the payload once. It is a cast attribute, so every $import->payload would
        // re-run json_decode over the whole jsonb column. Passing the array on costs nothing:
        // PHP hands it over by refcount and only copies if a callee writes to it, which none do.
        $payload = $import->payload;
        $total = count($payload);
        $size = $this->sliceSize();

        // array_slice, not array_chunk: chunking would duplicate the whole payload up front.
        // This keeps one slice alive at a time.
        for ($offset = 0; $offset < $total; $offset += $size) {
            $slice = array_slice($payload, $offset, $size);
            $done = $offset + count($slice);

            try {
                DB::transaction(function () use ($import, $slice, $done) {
                    $this->writeSlice($import, $slice);

                    $import->update(['processed_offers' => $done]);
                });
            } catch (Throwable $e) {
                // This slice rolled back; the earlier ones are committed and stay. The spec
                // says an error means `failed`, and a half-succeeded import reporting
                // `completed` would be a lie.
                $this->markImportAsFailed($import, $e);

                return;
            }
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * The original row-by-row path, kept only as a baseline to measure the bulk one against.
     * Nothing in the application calls it — see `php artisan import:test --row-by-row`.
     *
     * Two differences in behaviour, both inherent to writing one offer at a time:
     * one transaction per offer, so a failure leaves the offers already written in place and
     * `processed_offers` reports real partial progress; and roughly three queries per offer
     * instead of two per slice.
     */
    public function createOffersFromPayloadRowByRow(Import $import): void
    {
        if ($import->status->isTerminal()) {
            return;
        }

        $import->update([
            'status' => ImportStatus::Processing,
            'processed_offers' => 0,
            'error' => null,
            'completed_at' => null,
        ]);

        foreach ($import->payload as $payload) {
            try {
                DB::transaction(fn () => $this->importOfferBad($import, $payload));
            } catch (Throwable $e) {
                $this->markImportAsFailed($import, $e);

                return;
            }
        }

        $import->update([
            'status' => ImportStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importOfferBad(Import $import, array $payload): void
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

    /**
     * Also used by the Job's failed() hook, for exceptions that never reached the try block.
     */
    public function markImportAsFailed(Import $import, ?Throwable $e): void
    {
        $import->update([
            'status' => ImportStatus::Failed,
            // A failed bulk statement puts every binding in the exception message, so cap it.
            'error' => Str::limit($e?->getMessage() ?? 'Import failed.', 2000),
            'completed_at' => now(),
        ]);
    }

    /**
     * CHUNK, capped so neither statement can exceed Postgres' bind parameter limit however
     * CHUNK is tuned. The offers statement is the wider of the two.
     */
    private function sliceSize(): int
    {
        return min(
            self::CHUNK,
            intdiv(self::MAX_BIND_PARAMS - 2, self::OFFER_PARAMS),
            intdiv(self::MAX_BIND_PARAMS, self::PROPERTY_PARAMS),
        );
    }

    /**
     * One pass over the slice fills both VALUES lists; then two statements write them.
     *
     * The offers statement resolves property_id by joining `properties` on the codes the
     * first statement just wrote, so there is no id lookup, no [code => id] map in PHP and
     * no second pass over the slice.
     *
     * @param  list<array<string, mixed>>  $slice
     */
    private function writeSlice(Import $import, array $slice): void
    {
        // Keyed on their conflict targets: Postgres refuses an ON CONFLICT statement that
        // would touch the same row twice ("cannot affect row a second time"). Last one wins,
        // and the HTTP layer rejects duplicate external_ids before they ever get here.
        $properties = [];
        $offers = [];

        foreach ($slice as $offer) {
            $property = $offer['property'];

            $properties[$property['code']] = [
                $property['code'],
                $property['name'],
                $property['city'],
            ];

            $offers[$offer['external_id']] = [
                $offer['external_id'],
                // The join key. Its row is written by the statement that runs first.
                $property['code'],
                $offer['check_in'],
                $offer['check_out'],
                $offer['max_guests'],
                $offer['price'],
                $offer['currency'],
                $offer['available_units'],
                // No Carbon: the ::timestamptz cast parses it, and an explicit Z leaves no
                // room for the session timezone to shift it.
                $offer['expires_at'],
            ];
        }

        $this->writeProperties($properties);
        $this->writeOffers($import, $offers);
    }

    /**
     * @param  array<string, list<mixed>>  $properties  code => [code, name, city]
     */
    private function writeProperties(array $properties): void
    {
        if ($properties === []) {
            return;
        }

        $bindings = [];

        foreach ($properties as $row) {
            array_push($bindings, ...$row);
        }

        DB::affectingStatement(sprintf('
            insert into properties (code, name, city, created_at, updated_at)
            select v.code, v.name, v.city, now(), now()
            from (values %s) as v (code, name, city)
            on conflict (code) do update
               set name = excluded.name,
                   city = excluded.city,
                   updated_at = now()
        ', $this->placeholders(count($properties), self::PROPERTY_PARAMS)), $bindings);
    }

    /**
     * @param  array<string, list<mixed>>  $offers  external_id => [external_id, code, ...]
     */
    private function writeOffers(Import $import, array $offers): void
    {
        if ($offers === []) {
            return;
        }

        // supplier_id and import_id are constant for the whole import, so they are bound
        // once in the select list rather than repeated on every row.
        $bindings = [$import->supplier_id, $import->id];

        foreach ($offers as $row) {
            array_push($bindings, ...$row);
        }

        $affected = DB::affectingStatement(sprintf('
            insert into offers (supplier_id, property_id, import_id, external_id, check_in,
                                check_out, max_guests, price, currency, available_units,
                                expires_at, created_at, updated_at)
            select ?::bigint, p.id, ?::bigint, v.external_id, v.check_in::date,
                   v.check_out::date, v.max_guests::smallint, v.price::bigint,
                   upper(v.currency)::char(3), v.available_units::int,
                   v.expires_at::timestamptz, now(), now()
            from (values %s) as v (external_id, code, check_in, check_out, max_guests,
                   price, currency, available_units, expires_at)
            join properties p on p.code = v.code
            on conflict (supplier_id, external_id) do update
               set property_id = excluded.property_id,
                   import_id = excluded.import_id,
                   check_in = excluded.check_in,
                   check_out = excluded.check_out,
                   max_guests = excluded.max_guests,
                   price = excluded.price,
                   currency = excluded.currency,
                   available_units = excluded.available_units,
                   expires_at = excluded.expires_at,
                   updated_at = now()
        ', $this->placeholders(count($offers), self::OFFER_PARAMS)), $bindings);

        // An inner join silently drops an offer whose property is missing, turning a bug into
        // missing data. writeProperties() just wrote every code in this slice, so a short
        // count means something is wrong and the import must fail rather than under-report.
        if ($affected !== count($offers)) {
            throw new WGTSpainException(sprintf(
                'Expected to write %d offers, wrote %d — a property code went missing.',
                count($offers),
                $affected,
            ), 500);
        }
    }

    /** Builds `(?, ?, ?), (?, ?, ?), ...` for a VALUES list. */
    private function placeholders(int $rows, int $perRow): string
    {
        return implode(', ', array_fill(
            0,
            $rows,
            '('.implode(', ', array_fill(0, $perRow, '?')).')'
        ));
    }
}
