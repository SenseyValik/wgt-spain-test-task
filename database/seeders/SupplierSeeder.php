<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * The two suppliers required by the spec. Keyed on code so re-seeding is idempotent.
     * Runs after UserSeeder, since the account is resolved by email.
     */
    public function run(): void
    {
        $suppliers = [
            ['code' => 'supplier-a', 'name' => 'Supplier A', 'email' => 'supplier-a@example.com'],
            ['code' => 'supplier-b', 'name' => 'Supplier B', 'email' => 'supplier-b@example.com'],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::query()->updateOrCreate(
                ['code' => $supplier['code']],
                [
                    'name' => $supplier['name'],
                    'user_id' => User::query()->where('email', $supplier['email'])->value('id'),
                ]
            );
        }
    }
}
