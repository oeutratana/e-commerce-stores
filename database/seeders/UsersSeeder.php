<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UsersSeeder extends Seeder
{
    /**
     * Seed the users table with an admin account and sample customers.
     */
    public function run(): void
    {
        $password = 'password';

        $users = [
            [
                'name' => 'Drink Admin',
                'email' => 'admin@drink.com',
                'phone' => '+855 12 345 678',
                'address' => 'Phnom Penh, Cambodia',
                'is_admin' => true,
            ],
            [
                'name' => 'Sokha Dara',
                'email' => 'sokha@example.com',
                'phone' => '+855 12 000 001',
                'address' => 'Street 271, Phnom Penh, Cambodia',
                'is_admin' => false,
            ],
            [
                'name' => 'Chanraksmey',
                'email' => 'chan@example.com',
                'phone' => '+855 12 000 002',
                'address' => 'Siem Reap, Cambodia',
                'is_admin' => false,
            ],
            [
                'name' => 'Malis Chheng',
                'email' => 'malis@example.com',
                'phone' => '+855 12 000 003',
                'address' => 'Battambang, Cambodia',
                'is_admin' => false,
            ],
            [
                'name' => 'Vannak Lim',
                'email' => 'vannak@example.com',
                'phone' => '+855 12 000 004',
                'address' => 'Kampot, Cambodia',
                'is_admin' => false,
            ],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'email_verified_at' => now(),
                    'password' => $password,
                    'phone' => $user['phone'],
                    'address' => $user['address'],
                    'is_admin' => $user['is_admin'],
                    'remember_token' => Str::random(10),
                ]
            );
        }
    }
}
