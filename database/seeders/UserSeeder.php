<?php

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Admin plus one account per seeded supplier, plus a client for manual poking.
     * Keyed on email so re-seeding is idempotent.
     */
    public function run(): void
    {
        $password = Hash::make(env('SEED_PASSWORD', 'password'));

        $users = [
            ['email' => 'admin@example.com', 'name' => 'Admin', 'type' => UserType::Admin],
            ['email' => 'supplier-a@example.com', 'name' => 'Supplier A', 'type' => UserType::Supplier],
            ['email' => 'supplier-b@example.com', 'name' => 'Supplier B', 'type' => UserType::Supplier],
            ['email' => 'client@example.com', 'name' => 'Client', 'type' => UserType::Client],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'type' => $user['type'],
                    'password' => $password,
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
