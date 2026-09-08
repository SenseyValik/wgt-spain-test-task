<?php

namespace App\Console\Commands;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Development helper: queue a synthetic import of N offers and print its id.
 *
 * Nothing is processed here — the job goes to Redis and a worker picks it up, which is the
 * point: this exercises the real queue path rather than calling the service inline.
 */
class GenerateImport extends Command
{
    protected $signature = 'import:generate
        {rows=100 : How many offers to generate}
        {--supplier=supplier-a : Supplier code to import for}
        {--row-by-row : Process with the row-by-row path instead of the bulk one}';

    protected $description = 'Generate an import of N offers, queue it, and print its id';

    public function handle(Cache $cache): int
    {
        // A large payload blows the default 128M CLI limit, and PHP then has too little
        // memory left to print the fatal error — the command would just exit silently.
        ini_set('memory_limit', '-1');

        $rows = (int) $this->argument('rows');
        $code = (string) $this->option('supplier');
        $rowByRow = (bool) $this->option('row-by-row');

        if ($rows < 1) {
            $this->components->error('rows must be at least 1.');

            return self::FAILURE;
        }

        $supplier = Supplier::query()->where('code', $code)->first();

        if (! $supplier) {
            $this->components->error("No supplier '{$code}'. Run: php artisan db:seed");

            return self::FAILURE;
        }

        $offers = $this->payload($rows, $code);

        $import = Import::query()->create([
            'supplier_id' => $supplier->id,
            // Unique per run, so every invocation is a genuinely new import rather than an
            // idempotent resend that would queue nothing.
            'external_import_id' => 'generated-'.now()->format('Ymd-His-v').'-'.$rows,
            'sent_at' => now(),
            'status' => ImportStatus::Pending,
            'payload' => $offers,
            'total_offers' => $rows,
            'processed_offers' => 0,
        ]);

        $job = new ProcessImportJob($import, $rowByRow);

        // ProcessImportJob is ShouldBeUnique, keyed on the import id, and that lock lives in
        // Redis while the ids live in Postgres. A migrate:fresh restarts the ids but leaves the
        // locks, so a lock held by an earlier run silently swallows this dispatch — no job, no
        // error, an import stuck on `pending` forever. Releasing the lock for this one id first
        // removes that failure mode; it is safe because a freshly created import cannot already
        // be in flight.
        (new UniqueLock($cache))->release($job);

        dispatch($job);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=green;options=bold>import id</>', "<options=bold>{$import->id}</>");
        $this->components->twoColumnDetail('offers queued', (string) $rows);
        $this->components->twoColumnDetail('supplier', $code);
        $this->components->twoColumnDetail('path', $rowByRow ? 'row by row' : 'bulk');
        $this->components->twoColumnDetail('status', $import->status->value);
        $this->newLine();

        $this->line('  Waiting for a worker. Start one if none is running:');
        $this->line('    <fg=gray>php artisan queue:work redis</>');
        $this->newLine();
        $this->line('  Then watch it:');
        $this->line("    <fg=gray>curl localhost:8000/api/imports/{$import->id}</>");
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payload(int $rows, string $supplier): array
    {
        $offers = [];
        // Stamped once per run so repeat runs rewrite the same offers rather than growing the
        // table forever, and computed once rather than per row.
        $batch = now()->format('His');
        $expiresAt = now()->addMonths(3)->toIso8601ZuluString();

        for ($i = 1; $i <= $rows; $i++) {
            $offers[] = [
                'external_id' => sprintf('%s-gen-%s-%06d', $supplier, $batch, $i),
                'property' => [
                    'code' => sprintf('GEN-%06d', $i),
                    'name' => 'Generated apartment '.$i,
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
