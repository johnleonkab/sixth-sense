<?php

namespace Database\Factories;

use App\Models\AgentProduction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentProduction>
 */
class AgentProductionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ref' => 'prod_'.Str::lower(Str::random(16)),
            'user_id' => User::factory(),
            'kind' => AgentProduction::VIEW,
            'summary' => 'Uso de la semana',
            'canvas' => ['view' => 'openui.view', 'props' => ['title' => 'Uso', 'source' => 'Analytics', 'ui' => "root = View(\"Uso\", [m])\nm = Metric(\"Usuarios\", \"1.204\")", 'items' => []], 'layout' => 'wide'],
        ];
    }
}
