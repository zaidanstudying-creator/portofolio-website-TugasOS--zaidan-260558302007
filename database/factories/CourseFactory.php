<?php

namespace Database\Factories;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
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
            'semester_id' => null,
            'name' => fake()->words(3, true),
            'code' => fake()->bothify('##??###'),
            'instructor' => fake()->name(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
