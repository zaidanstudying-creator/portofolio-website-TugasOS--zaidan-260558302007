<?php

namespace Database\Factories;

use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRequest>
 */
class AiRequestFactory extends Factory
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
            'purpose' => 'task_breakdown',
            'provider' => 'openai',
            'model' => 'gpt-6-astra',
            'status' => 'pending',
            'input_hash' => fake()->sha256(),
            'consented_at' => now(),
        ];
    }
}
