<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WorkContext;
use App\WorkContext\WorkContextStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkContext>
 */
class WorkContextFactory extends Factory
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
            'enabled' => true,
            'status' => WorkContextStatus::Idle,
        ];
    }

    /**
     * Built last night.
     */
    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => WorkContextStatus::Ready,
            'summary' => 'Esta semana John está cerrando el lanzamiento del prepago con Marta y Pablo, y el contrato con Grupo Vela.',
            'digest' => [
                'summary' => 'Esta semana John está cerrando el lanzamiento del prepago con Marta y Pablo, y el contrato con Grupo Vela.',
                'workstreams' => [['name' => 'Lanzamiento del prepago', 'what' => 'Revisión del flujo antes del 20 de octubre', 'why' => 'Reducir los no-shows', 'with' => ['Marta Ruiz', 'Pablo Gil'], 'where' => ['Slack #producto', 'Notion'], 'next' => 'Ok de Riesgos']],
                'people' => [['name' => 'Marta Ruiz', 'relation' => 'Product Manager, lleva el prepago']],
                'open_loops' => ['Confirmar a Pablo el reembolso parcial'],
            ],
            'sources' => ['slack' => 42, 'gmail' => 30, 'docs' => 5, 'notion' => 3],
            'generated_at' => now(),
        ]);
    }
}
