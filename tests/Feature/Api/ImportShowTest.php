<?php

namespace Tests\Feature\Api;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_current_state_of_an_import(): void
    {
        $supplier = Supplier::factory()->create(['code' => 'supplier-a']);
        $import = Import::factory()->for($supplier)->completed(offers: 20)->create([
            'external_import_id' => 'import-2026-09-01-001',
        ]);

        $this->getJson("/api/imports/{$import->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $import->id)
            ->assertJsonPath('data.supplier', 'supplier-a')
            ->assertJsonPath('data.external_import_id', 'import-2026-09-01-001')
            ->assertJsonPath('data.status', ImportStatus::Completed->value)
            ->assertJsonPath('data.total_offers', 20)
            ->assertJsonPath('data.processed_offers', 20)
            ->assertJsonPath('data.error', null)
            ->assertJsonStructure([
                'data' => [
                    'id', 'supplier', 'external_import_id', 'sent_at', 'status',
                    'total_offers', 'processed_offers', 'error', 'created_at', 'completed_at',
                ],
            ]);
    }

    public function test_it_returns_404_as_json_for_an_unknown_import(): void
    {
        $this->getJson('/api/imports/999')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json');
    }
}
