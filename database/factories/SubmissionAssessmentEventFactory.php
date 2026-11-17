<?php

namespace Database\Factories;

use App\Models\SubmissionAssessmentEvent;
use App\Models\TaskSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubmissionAssessmentEvent>
 */
class SubmissionAssessmentEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_submission_id' => TaskSubmission::factory(),
            'user_id' => fn (array $attributes): int => (int) TaskSubmission::query()
                ->whereKey($attributes['task_submission_id'])
                ->value('user_id'),
            'event_type' => 'change',
            'from_status' => 'pending',
            'to_status' => fake()->randomElement(['graded', 'revision_required']),
        ];
    }
}
