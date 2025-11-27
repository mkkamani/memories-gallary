<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'album_id' => Album::factory(),
            'file_path' => 'uploads/' . fake()->uuid() . '.jpg',
            'file_name' => fake()->word() . '.jpg',
            'file_type' => 'image',
            'file_size' => fake()->numberBetween(100000, 5000000),
            'mime_type' => 'image/jpeg',
            'taken_at' => fake()->dateTime(),
        ];
    }
}
