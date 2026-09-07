<?php

namespace Database\Factories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->randomElement(['Barcelona', 'Madrid', 'Valencia', 'Seville']);

        return [
            'code' => Str::upper(Str::substr($city, 0, 3)).'-'.fake()->unique()->numerify('####'),
            'name' => 'Apartment '.fake()->streetName(),
            'city' => $city,
        ];
    }

    public function inCity(string $city): static
    {
        return $this->state(fn () => ['city' => $city]);
    }
}
