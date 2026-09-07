<?php

namespace Database\Factories;

use App\Enums\UserType;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'code' => 'supplier-'.$n,
            'name' => 'Supplier '.$n,
            // Null by default: most tests care about imports, not accounts, and should not
            // pay for a user insert.
            'user_id' => null,
        ];
    }

    /**
     * Attach a supplier-type account.
     */
    public function withUser(): static
    {
        return $this->state(fn () => [
            'user_id' => User::factory()->state(['type' => UserType::Supplier]),
        ]);
    }
}
