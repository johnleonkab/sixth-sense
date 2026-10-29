<?php

namespace Database\Factories;

use App\Models\SenseSkill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SenseSkill>
 */
class SenseSkillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'description' => fake()->sentence(),
            'instructions' => fake()->paragraph(),
            'files' => [],
            'model_invocable' => true,
            'source_path' => ".claude/skills/{$name}/SKILL.md",
            'source_sha' => fake()->sha1(),
        ];
    }
}
