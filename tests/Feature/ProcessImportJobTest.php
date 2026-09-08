<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use App\Services\ImportProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessImportJobTest extends TestCase
{
    use RefreshDatabase;

    private function offerPayload(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'offer-a-10001',
            'property' => [
                'code' => 'BCN-0001',
                'name' => 'Apartment near Sagrada Familia',
                'city' => 'Barcelona',
            ],
            'check_in' => '2026-10-10',
            'check_out' => '2026-10-15',
            'max_guests' => 4,
            'price' => 72500,
            'currency' => 'EUR',
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

    private function process(Import $import): void
    {
        (new ProcessImportJob($import))->handle(app(ImportProcessor::class));
    }

    public function test_it_creates_properties_and_offers_and_completes(): void
    {
        $supplier = Supplier::factory()->create();
        $import = $this->import($supplier, [
            $this->offerPayload(),
            $this->offerPayload([
                'external_id' => 'offer-a-10002',
                'property' => ['code' => 'BCN-0002', 'name' => 'Loft Gracia', 'city' => 'Barcelona'],
                'price' => 51000,
            ]),
        ]);

        $this->process($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(2, $import->processed_offers);
        $this->assertSame($import->total_offers, $import->processed_offers);
        $this->assertNotNull($import->completed_at);
        $this->assertNull($import->error);

        $this->assertSame(2, Property::count());
        $this->assertSame(2, Offer::count());

        $offer = Offer::where('external_id', 'offer-a-10001')->sole();
        $this->assertSame(72500, $offer->price);
        $this->assertSame('EUR', $offer->currency);
        $this->assertSame($import->id, $offer->import_id);
        $this->assertSame('BCN-0001', $offer->property->code);
    }

    public function test_an_offer_seen_in_a_later_import_is_updated_not_duplicated(): void
    {
        $supplier = Supplier::factory()->create();

        $first = $this->import($supplier, [$this->offerPayload()]);
        $this->process($first);

        $second = $this->import($supplier, [
            $this->offerPayload(['price' => 60000, 'available_units' => 5]),
        ], ['external_import_id' => 'import-2026-09-02-001']);
        $this->process($second);

        $this->assertSame(1, Offer::count(), 'The offer must be updated, not duplicated.');
        $this->assertSame(1, Property::count(), 'The property must be matched by code.');

        $offer = Offer::sole();
        $this->assertSame(60000, $offer->price);
        $this->assertSame(5, $offer->available_units);
        $this->assertSame($second->id, $offer->import_id, 'import_id must be repointed.');
    }

    public function test_the_same_external_id_from_a_different_supplier_is_a_separate_offer(): void
    {
        $a = Supplier::factory()->create(['code' => 'supplier-a']);
        $b = Supplier::factory()->create(['code' => 'supplier-b']);

        $this->process($this->import($a, [$this->offerPayload()]));
        $this->process($this->import($b, [$this->offerPayload()]));

        $this->assertSame(2, Offer::count());
        $this->assertSame(1, Property::count(), 'Both suppliers must share the property.');
    }

    public function test_a_bad_offer_fails_the_import_and_preserves_progress(): void
    {
        $supplier = Supplier::factory()->create();
        $import = $this->import($supplier, [
            $this->offerPayload(),
            // check_out before check_in trips the DB CHECK constraint. The HTTP layer would
            // have rejected this; here it stands in for any mid-import failure.
            $this->offerPayload(['external_id' => 'offer-a-broken', 'check_out' => '2026-10-01']),
            $this->offerPayload(['external_id' => 'offer-a-10003']),
        ]);

        $this->process($import);

        $import->refresh();
        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertStringContainsString('offer-a-broken', (string) $import->error);
        $this->assertNotNull($import->completed_at);
        $this->assertSame(1, $import->processed_offers, 'Progress before the failure must survive.');
        $this->assertSame(1, Offer::count());
    }

    public function test_it_is_a_noop_for_an_import_that_already_finished(): void
    {
        $supplier = Supplier::factory()->create();
        $import = $this->import($supplier, [$this->offerPayload()], [
            'status' => ImportStatus::Completed,
            'processed_offers' => 1,
        ]);

        $this->process($import);

        $this->assertSame(0, Offer::count(), 'A redelivered job must not reprocess.');
        $this->assertSame(1, $import->refresh()->processed_offers);
    }

    public function test_reprocessing_does_not_double_count_progress(): void
    {
        $supplier = Supplier::factory()->create();
        $import = $this->import($supplier, [$this->offerPayload()]);

        $this->process($import);
        // Force a retry of an import left mid-flight.
        $import->update(['status' => ImportStatus::Processing]);
        $this->process($import);

        $import->refresh();
        $this->assertSame(1, $import->processed_offers);
        $this->assertSame(1, Offer::count());
    }
}
