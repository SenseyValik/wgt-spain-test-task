<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers ImportService::createOffersFromPayloadRowByRow(), the baseline kept for measuring
 * the bulk path against. The point of these tests is equivalence: the two paths must write
 * the same rows, so a benchmark between them compares like with like.
 */
class ImportRowByRowTest extends TestCase
{
    use RefreshDatabase;

    private function offerPayload(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'offer-10001',
            'property' => [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'eur',
            'available_units' => 2,
            'expires_at' => '2026-09-10T23:59:59Z',
        ], $overrides);
    }

    private function import(Supplier $supplier, array $offers, array $overrides = []): Import
    {
        return Import::factory()->for($supplier)->create(array_merge([
            'payload' => $offers,
            'total_offers' => count($offers),
        ], $overrides));
    }

    private function service(): ImportService
    {
        return app(ImportService::class);
    }

    public function test_it_creates_properties_and_offers_and_completes(): void
    {
        $supplier = Supplier::factory()->create();
        $import = $this->import($supplier, [
            $this->offerPayload(),
            $this->offerPayload([
                'external_id' => 'offer-10002',
                'property' => ['code' => 'BCN-0002', 'name' => 'Loft Gracia', 'city' => 'Barcelona'],
                'price' => 51000,
            ]),
        ]);

        $this->service()->createOffersFromPayloadRowByRow($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->processed_offers);
        $this->assertNotNull($import->completed_at);
        $this->assertNull($import->error);

        $this->assertSame(2, Property::count());
        $this->assertSame(2, Offer::count());

        $offer = Offer::where('external_id', 'offer-10001')->sole();
        $this->assertSame(72500, $offer->price);
        $this->assertSame('EUR', $offer->currency, 'currency must be upper-cased');
        $this->assertSame($import->id, $offer->import_id);
        $this->assertSame('BCN-0001', $offer->property->code);
    }

    /**
     * The reason this class exists: if the two paths disagree on a single column, any timing
     * comparison between them is meaningless.
     */
    public function test_it_writes_exactly_what_the_bulk_path_writes(): void
    {
        $payload = [
            $this->offerPayload(),
            $this->offerPayload([
                'external_id' => 'offer-10002',
                'property' => ['code' => 'BCN-0002', 'name' => 'Loft Gracia', 'city' => 'Valencia'],
                'price' => 51000,
                'check_in' => '2026-11-01',
                'check_out' => '2026-11-03',
                'max_guests' => 2,
                'available_units' => 7,
                'expires_at' => '2026-10-20T08:30:00Z',
            ]),
        ];

        $bulk = Supplier::factory()->create(['code' => 'bulk-supplier']);
        $rows = Supplier::factory()->create(['code' => 'rows-supplier']);

        $this->service()->createOffersFromPayload($this->import($bulk, $payload));
        $this->service()->createOffersFromPayloadRowByRow($this->import($rows, $payload));

        $columns = ['external_id', 'check_in', 'check_out', 'max_guests', 'price', 'currency',
            'available_units', 'expires_at', 'property_id'];

        $written = fn (Supplier $supplier) => Offer::query()
            ->where('supplier_id', $supplier->id)
            ->orderBy('external_id')
            ->get($columns)
            ->map(fn (Offer $offer) => $offer->only($columns))
            ->all();

        $this->assertEquals(
            $written($bulk),
            $written($rows),
            'The bulk and row-by-row paths must write identical offers.'
        );
        $this->assertSame(2, Property::count(), 'Both paths must match properties by code.');
    }

    public function test_it_costs_roughly_three_queries_per_offer(): void
    {
        $supplier = Supplier::factory()->create();

        $count = function (Import $import): int {
            $queries = 0;
            $listener = function () use (&$queries) {
                $queries++;
            };
            DB::listen($listener);

            $this->service()->createOffersFromPayloadRowByRow($import);

            return $queries;
        };

        $payload = [];

        for ($i = 1; $i <= 10; $i++) {
            $payload[] = $this->offerPayload([
                'external_id' => 'offer-'.$i,
                'property' => ['code' => 'BCN-'.$i, 'name' => 'Apt '.$i, 'city' => 'Barcelona'],
            ]);
        }

        $queries = $count($this->import($supplier, $payload));

        // This is the cost the bulk path exists to remove: it grows with the offer count,
        // where the bulk path grows with the slice count.
        $this->assertGreaterThan(
            10 * 3,
            $queries,
            'The row-by-row path is expected to cost more than three queries per offer.'
        );
        $this->assertSame(10, Offer::count());
    }

    public function test_a_bad_offer_keeps_the_progress_made_before_it(): void
    {
        $supplier = Supplier::factory()->create();
        $import = $this->import($supplier, [
            $this->offerPayload(),
            // check_out before check_in trips the DB CHECK constraint.
            $this->offerPayload(['external_id' => 'offer-broken', 'check_out' => '2026-10-01']),
            $this->offerPayload(['external_id' => 'offer-10003']),
        ]);

        $this->service()->createOffersFromPayloadRowByRow($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->completed_at);

        // Unlike the bulk path, which rolls the whole import back, this one commits per offer.
        $this->assertSame(1, $import->processed_offers);
        $this->assertSame(1, Offer::count());
    }

    public function test_it_is_a_noop_for_an_import_that_already_finished(): void
    {
        $supplier = Supplier::factory()->create();
        $import = $this->import($supplier, [$this->offerPayload()], [
            'status' => ImportStatus::Completed,
            'processed_offers' => 1,
        ]);

        $this->service()->createOffersFromPayloadRowByRow($import);

        $this->assertSame(0, Offer::count());
        $this->assertSame(1, $import->refresh()->processed_offers);
    }
}
