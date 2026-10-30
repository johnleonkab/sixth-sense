<?php

namespace Database\Factories;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedView>
 */
class SavedViewFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(2, true),
            'title' => 'Tráfico de la semana',
            'source' => 'Google Analytics',
            'ui' => "root = View(\"Tráfico de la semana\", [m])\nm = Metric(\"Usuarios\", \"1.204\")",
            'items' => [],
        ];
    }
}
