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
            'name' => $slug,
            'description' => fake()->sentence(),
            'instructions' => fake()->paragraph(),
            'skills' => [],
            'source_path' => ".claude/agents/{$slug}.md",
            'source_sha' => fake()->sha1(),
        ];
    }
}
