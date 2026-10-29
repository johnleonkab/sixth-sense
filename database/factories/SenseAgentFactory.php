<?php

namespace Database\Factories;

use App\Models\SenseAgent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SenseAgent>
 */
class SenseAgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(1);

        return [
            'user_id' => User::factory(),
            'slug' => $slug,
            'name' => fake()->words(2, true),
            'purpose' => fake()->sentence(),
            'cue' => fake()->sentence(),
            'instructions' => fake()->paragraph(),
            'source_path' => "agents/{$slug}/agent.md",
            'source_sha' => fake()->sha1(),
        ];
    }
}
