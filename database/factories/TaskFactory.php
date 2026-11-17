<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->boolean()
            ? fake()->dateTimeBetween('now', '+1 week')
            : null;

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'type' => fake()->randomElement(['daily', 'weekly', 'semester', 'flexible']),
            'status' => fake()->randomElement(['todo', 'in_progress', 'done']),
            'priority' => fake()->randomElement(['low', 'medium', 'high', 'urgent']),
            'starts_at' => $startsAt,
            'due_at' => $startsAt === null
                ? fake()->optional()->dateTimeBetween('now', '+1 month')
                : fake()->optional()->dateTimeBetween($startsAt, '+1 month'),
            'weight' => fake()->numberBetween(1, 5),
        ];
    }
}
