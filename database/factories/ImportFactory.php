<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'external_import_id' => 'import-'.fake()->unique()->numerify('####-##-##-###'),
            'sent_at' => now()->subMinutes(5),
            'status' => ImportStatus::Pending,
            'payload' => [],
            'total_offers' => 0,
            'processed_offers' => 0,
            'error' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => ImportStatus::Processing]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ImportStatus::Completed,
            'processed_offers' => $attributes['total_offers'] ?? 0,
            'completed_at' => now(),
        ]);
    }

    public function failed(string $error = 'Import failed'): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::Failed,
            'error' => $error,
            'completed_at' => now(),
        ]);
    }
}
