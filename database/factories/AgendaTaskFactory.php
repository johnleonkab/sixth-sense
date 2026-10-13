<?php

namespace Database\Factories;

use App\Models\AgendaTask;
use App\Models\User;
use App\Scheduling\AgendaTaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgendaTask>
 */
class AgendaTaskFactory extends Factory
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
            'origin' => 'voice',
            'title' => 'Revisar el PRD del prepago',
            'context' => 'Marta pide revisarlo antes del jueves.',
            'status' => AgendaTaskStatus::Assessing,
        ];
    }
}
