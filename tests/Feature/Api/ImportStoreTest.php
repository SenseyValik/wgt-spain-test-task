<?php

namespace Tests\Feature\Api;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportJob;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportStoreTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier' => 'supplier-a',
            'external_import_id' => 'import-2026-09-01-001',
            'sent_at' => '2026-09-01T10:00:00Z',
            'offers' => [
                [
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
                ],
            ],
        ], $overrides);
    }

    public function test_it_accepts_an_import_and_queues_processing(): void
    {
        Queue::fake();
        Supplier::factory()->create(['code' => 'supplier-a']);

        $response = $this->postJson('/api/imports', $this->payload());

        $response->assertStatus(202)
            ->assertJsonPath('data.status', ImportStatus::Pending->value)
            ->assertJsonStructure(['data' => ['id', 'status']]);

        $import = Import::sole();
        $this->assertSame('import-2026-09-01-001', $import->external_import_id);
        $this->assertSame(1, $import->total_offers);
        $this->assertSame(0, $import->processed_offers);

        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_it_rejects_an_unknown_supplier(): void
    {
        $this->postJson('/api/imports', $this->payload(['supplier' => 'supplier-z']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier');
    }

    public function test_it_rejects_a_malformed_offer(): void
    {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $payload = $this->payload();
        unset($payload['offers'][0]['price']);
        $payload['offers'][0]['check_out'] = '2026-10-01'; // before check_in

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['offers.0.price', 'offers.0.check_out']);
    }

    public function test_it_rejects_a_duplicated_external_id_within_one_payload(): void
    {
        Supplier::factory()->create(['code' => 'supplier-a']);

        $payload = $this->payload();
        $payload['offers'][1] = $payload['offers'][0];

        $this->postJson('/api/imports', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('offers.0.external_id');
    }

    /**
     * The headline requirement: a resend must neither duplicate nor reprocess.
     */
    public function test_resending_the_same_import_is_idempotent(): void
    {
        Queue::fake();
        Supplier::factory()->create(['code' => 'supplier-a']);

        $first = $this->postJson('/api/imports', $this->payload())->assertStatus(202);
        $second = $this->postJson('/api/imports', $this->payload())->assertStatus(202);

        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
            'A resent import must return the original import id.'
        );

        $this->assertSame(1, Import::count(), 'A resend must not create a second import.');
        Queue::assertPushed(ProcessImportJob::class, 1);
    }

    public function test_the_same_external_import_id_is_allowed_for_a_different_supplier(): void
    {
        Queue::fake();
        Supplier::factory()->create(['code' => 'supplier-a']);
        Supplier::factory()->create(['code' => 'supplier-b']);

        $this->postJson('/api/imports', $this->payload())->assertStatus(202);
        $this->postJson('/api/imports', $this->payload(['supplier' => 'supplier-b']))->assertStatus(202);

        $this->assertSame(2, Import::count());
        Queue::assertPushed(ProcessImportJob::class, 2);
    }
}
