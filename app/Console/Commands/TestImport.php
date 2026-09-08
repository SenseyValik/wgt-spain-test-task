<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Supplier;
use App\Services\ImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Development helper: fire a synthetic import of N offers and report what it cost.
 */
class TestImport extends Command
{
    /** Property codes and import ids the command owns, so cleanup can find them again. */
    private const PREFIX = 'import-test';

    protected $signature = 'import:test
        {offers=50 : How many offers to put in the payload}
        {--supplier=supplier-a : Supplier code to import for}
        {--queue : Only queue the import, let a worker process it}
        {--row-by-row : Use the old per-offer updateOrCreate path instead of the bulk one}
        {--keep : Leave the generated rows in the database}
        {--clean : Only delete rows left by earlier runs, import nothing}';

    protected $description = 'Import N generated offers and report queries, time and status';

    public function handle(ImportService $service): int
    {
        // A large payload blows the default 128M CLI limit, and PHP then has too little
        // memory left to print the fatal error — the command just exits silently.
        ini_set('memory_limit', '-1');

        if ($this->option('clean')) {
            $this->reportCleanup($this->cleanup());

            return self::SUCCESS;
        }

        $count = (int) $this->argument('offers');
        $code = (string) $this->option('supplier');

        $supplier = Supplier::query()->where('code', $code)->first();

        if (! $supplier) {
            $this->error("No supplier '{$code}'. Run: php artisan db:seed");

            return self::FAILURE;
        }

        // Processing happens inline below, so the job createAndQueueImport() dispatches would
        // be a no-op at best and, once the rows are deleted, a failing job at worst.
        if (! $this->option('queue')) {
            Queue::fake();
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $started = hrtime(true);

        $import = $service->createAndQueueImport([
            'supplier' => $code,
            'external_import_id' => self::PREFIX.'-'.now()->format('Ymd-His-v').'-'.$count,
            'sent_at' => now()->toIso8601ZuluString(),
            'offers' => $this->payload($count),
        ]);

        if ($this->option('queue')) {
            $this->info("Import #{$import->id} queued with {$count} offers.");
            $this->line('Process it with: php artisan queue:work redis --stop-when-empty');
            $this->line('Delete it afterwards with: php artisan import:test --clean');

            return self::SUCCESS;
        }

        $this->option('row-by-row')
            ? $service->createOffersFromPayloadRowByRow($import)
            : $service->createOffersFromPayload($import);

        $elapsed = (hrtime(true) - $started) / 1e6;
        $import->refresh();
        $status = $import->status;

        $this->table(['', ''], [
            ['import id', $import->id],
            ['offers sent', $count],
            ['path', $this->option('row-by-row') ? 'row by row' : 'bulk'],
            ['status', $status->value],
            ['processed_offers', $import->processed_offers],
            ['queries', $queries],
            ['peak memory', sprintf('%.0f MB', memory_get_peak_usage(true) / 1048576)],
            ['time', sprintf('%.0f ms', $elapsed)],
            ['error', $import->error ? mb_strimwidth($import->error, 0, 90, '…') : '—'],
        ]);

        // After the measurement, never inside it — a DELETE of 50k rows is not free, and
        // timing it together with the import would make the number meaningless.
        if ($this->option('keep')) {
            $this->line('Rows kept. Delete them with: php artisan import:test --clean');
        } else {
            $this->reportCleanup($this->cleanup());
        }

        return $status === ImportStatus::Completed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Delete everything this command has ever created, and nothing else.
     *
     * Ordered by the foreign keys: offers reference properties with restrictOnDelete, so the
     * offers go first. Properties are only dropped when no offer is left pointing at them,
     * which keeps a --keep run from having its properties pulled out from under it.
     *
     * @return array<string, int>
     */
    private function cleanup(): array
    {
        return DB::transaction(function (): array {
            $imports = Import::query()->where('external_import_id', 'like', self::PREFIX.'-%');

            $offers = DB::table('offers')
                ->whereIn('import_id', $imports->clone()->select('id'))
                ->delete();

            $properties = DB::table('properties')
                ->where('code', 'like', 'TEST-%')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('offers')
                    ->whereColumn('offers.property_id', 'properties.id'))
                ->delete();

            return [
                'offers' => $offers,
                'properties' => $properties,
                'imports' => $imports->delete(),
            ];
        });
    }

    /** @param  array<string, int>  $deleted */
    private function reportCleanup(array $deleted): void
    {
        if (array_sum($deleted) === 0) {
            $this->line('Nothing to clean up.');

            return;
        }

        $this->line(sprintf(
            'Cleaned up: %d offers, %d properties, %d imports.',
            $deleted['offers'],
            $deleted['properties'],
            $deleted['imports'],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payload(int $count): array
    {
        $offers = [];
        $expiresAt = now()->addMonths(3)->toIso8601ZuluString();

        for ($i = 1; $i <= $count; $i++) {
            $offers[] = [
                'external_id' => sprintf('%s-test-%06d', $this->option('supplier'), $i),
                'property' => [
                    'code' => sprintf('TEST-%06d', $i),
                    'name' => 'Test apartment '.$i,
                    'city' => 'Barcelona',
                ],
                'check_in' => '2026-10-10',
                'check_out' => '2026-10-15',
                'max_guests' => 4,
                'price' => 50000 + $i,
                'currency' => 'EUR',
                'available_units' => 3,
                'expires_at' => $expiresAt,
            ];
        }

        return $offers;
    }
}
