<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
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
            'task_id' => Task::factory(),
            'remind_at' => fake()->dateTimeBetween('now', '+7 days'),
            'message' => fake()->optional()->sentence(),
            'dismissed_at' => null,
        ];
    }
}
