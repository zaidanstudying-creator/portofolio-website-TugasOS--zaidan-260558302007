<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskSubmission>
 */
class TaskSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => fn (array $attributes): int => (int) Task::query()
                ->whereKey($attributes['task_id'])
                ->value('user_id'),
            'platform' => 'manual',
            'status' => 'manual_recorded',
            'assessment_status' => 'pending',
            'confirmation_method' => 'manual',
        ];
    }
}
