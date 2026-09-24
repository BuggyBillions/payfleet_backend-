<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            [
                'email' => 'admin@payfleet.com',
            ],
            [
                'name' => 'Administrator',
                'password' => Hash::make('Admin@123456'),
                'role' => 'admin',
                'is_active' => 1,
                'otp' => null,
                'otp_expires_at' => null,
                'is_verified' => 1,
                'email_verified_at' => now(),
                'remember_token' => null,
            ]
        );
    }
}