<?php

namespace Database\Seeders;

use App\Models\User;
use App\Notifications\NewUserAccountCreatedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AllEmployeesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = base_path('public/All_Employees_test.csv');

        if (! file_exists($csvPath)) {
            $this->command->warn("CSV file not found: {$csvPath}. Skipping all employees seeding.");
            return;
        }

        if (! $handle = fopen($csvPath, 'r')) {
            $this->command->error("Could not open CSV file: {$csvPath}");
            return;
        }

        $header = fgetcsv($handle);

        if (! $header || count($header) < 3) {
            $this->command->error('Invalid CSV header. Expect: Employee Number, Full Name, Email, Location, Role.');
            fclose($handle);
            return;
        }

        $normalized = array_map(fn ($value) => strtolower(trim($value)), $header);

        $rowCount = 0;
        $createdCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowCount++;

            if (count($row) !== count($header)) {
                $this->command->warn("Skipping malformed row #{$rowCount}: column mismatch.");
                continue;
            }

            $record = array_combine($normalized, $row);
            if (! $record) {
                $this->command->warn("Skipping row #{$rowCount}: could not combine columns.");
                continue;
            }

            $name = trim($record['full name'] ?? '');
            $email = trim($record['email'] ?? '');
            $location = trim($record['location'] ?? 'Rajkot');
            $role = strtolower(trim($record['role'] ?? ''));

            if (! $name || ! $email) {
                $this->command->warn("Skipping row #{$rowCount}: missing name/email.");
                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->command->warn("Skipping row #{$rowCount}: invalid email '{$email}'.");
                continue;
            }

            if (User::where('email', $email)->exists()) {
                $this->command->info("Skipping existing user with email: {$email}");
                continue;
            }

            if (! in_array($role, ['admin', 'manager'], true)) {
                $role = 'member';
            }

            $plainPassword = $this->generateSecurePassword(12);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($plainPassword),
                'role' => $role,
                'location' => $location ?: 'Rajkot',
            ]);

            try {
                $user->notify(new NewUserAccountCreatedNotification(
                    plainPassword: $plainPassword,
                ));
                $this->command->info("Created and emailed user: {$email} ({$role})");
            } catch (\Throwable $e) {
                Log::error('Failed to send new user notification during AllEmployeesSeeder', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
                $this->command->warn("Created user {$email} but email notification failed: {$e->getMessage()}");
            }

            $createdCount++;
        }

        fclose($handle);

        $this->command->info("AllEmployeesSeeder complete: {$createdCount} users created from {$rowCount} rows.");
    }

    /**
     * Generate a strong password with mixed character sets.
     */
    private function generateSecurePassword(int $length = 14): string
    {
        $length = max($length, 8);

        $sets = [
            'abcdefghijklmnopqrstuvwxyz',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789',
            '!@#$%&*()[]{}',
        ];

        $password = [];

        foreach ($sets as $set) {
            $password[] = $set[random_int(0, strlen($set) - 1)];
        }

        $all = implode('', $sets);
        $allLength = strlen($all);

        for ($i = count($password); $i < $length; $i++) {
            $password[] = $all[random_int(0, $allLength - 1)];
        }

        shuffle($password);

        return implode('', $password);
    }
}
