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

    /**
     * Pass the offer count explicitly — deriving it from $attributes would depend on
     * whether total_offers was set before or after this state was applied.
     */
    public function completed(int $offers = 0): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::Completed,
            'total_offers' => $offers,
            'processed_offers' => $offers,
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
