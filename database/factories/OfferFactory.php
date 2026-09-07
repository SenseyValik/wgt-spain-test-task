<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Property;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        $checkIn = now()->addMonth()->startOfDay();

        return [
            'supplier_id' => Supplier::factory(),
            'property_id' => Property::factory(),
            'import_id' => null,
            'external_id' => 'offer-'.fake()->unique()->numerify('#####'),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->copy()->addDays(5)->toDateString(),
            'max_guests' => 4,
            'price' => fake()->numberBetween(20_000, 150_000),
            'currency' => 'EUR',
            'available_units' => 2,
            'expires_at' => now()->addWeek(),
        ];
    }

    public function forDates(string $checkIn, string $checkOut): static
    {
        return $this->state(fn () => [
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);
    }

    public function priced(int $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    public function soldOut(): static
    {
        return $this->state(fn () => ['available_units' => 0]);
    }

    public function lastUnit(): static
    {
        return $this->state(fn () => ['available_units' => 1]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function forGuests(int $guests): static
    {
        return $this->state(fn () => ['max_guests' => $guests]);
    }
}
