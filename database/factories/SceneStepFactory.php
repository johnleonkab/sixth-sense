<?php

namespace Database\Factories;

use App\Models\Scene;
use App\Models\SceneStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SceneStep>
 */
class SceneStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scene_id' => Scene::factory(),
            'layer' => 2,
            'key' => fake()->unique()->slug(1),
            'status' => 'pending',
            'payload' => [],
            'error' => null,
            'pending_action_id' => null,
        ];
    }
}
