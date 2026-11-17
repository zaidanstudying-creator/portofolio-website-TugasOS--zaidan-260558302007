<?php

namespace Database\Factories;

use App\Models\Assigner;
use App\Models\AssignerRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignerRole>
 */
class AssignerRoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assigner_id' => Assigner::factory(),
            'name' => fake()->randomElement(['Dosen', 'Mentor', 'Pembimbing']),
        ];
    }
}
