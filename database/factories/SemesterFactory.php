<?php

namespace Database\Factories;

use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Semester>
 */
class SemesterFactory extends Factory
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
            'name' => fake()->unique()->bothify('Semester ?? ##'),
            'starts_at' => fake()->dateTimeBetween('-1 month', '+2 months')->format('Y-m-d'),
            'ends_at' => fake()->dateTimeBetween('+3 months', '+7 months')->format('Y-m-d'),
            'is_active' => false,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
