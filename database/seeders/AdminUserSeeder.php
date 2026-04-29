<?php

namespace Database\Seeders;

use App\Enums\AlbumLocation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = 'admin@gmail.com';

        if (User::where('email', $email)->exists()) {
            $this->command->info("Admin user already exists: {$email}");
            return;
        }

        User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'location' => AlbumLocation::Rajkot->value,
        ]);

        $this->command->info('Admin user created: admin@gmail.com / admin123');
    }
}
