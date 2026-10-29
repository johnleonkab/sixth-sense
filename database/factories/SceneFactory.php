<?php

namespace Database\Factories;

use App\Models\Scene;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scene>
 */
class SceneFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'inbox_item_id' => null,
            'type' => 'preview',
            'status' => 'open',
            'origin' => 'voice',
            'input' => [],
            'canvas' => [
                'title' => 'Escena',
                'cards' => [],
            ],
        ];
    }
}
