<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agent' => 'orquestador',
            'task' => 'Contesta a Marta y díselo a Alba',
            'status' => AgentRun::QUEUED,
        ];
    }

    public function done(string $say = 'Te lo dejo listo.'): static
    {
        return $this->state(['status' => AgentRun::DONE, 'say' => $say, 'finished_at' => now()]);
    }
}
