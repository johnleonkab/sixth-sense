<?php

namespace Database\Factories;

use App\Actions\ActionStatus;
use App\Models\PendingAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PendingAction>
 */
class PendingActionFactory extends Factory
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
            'type' => 'docs.comment',
            'label' => 'Comentar en «Flujo de prepago v4 — PRD»',
            'payload' => ['ref' => 'demo.1PrepagoFlujoV4demoDocument0001', 'content' => 'Revisado', 'quote' => null],
            'status' => ActionStatus::Pending,
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['status' => ActionStatus::Confirmed, 'undo_until' => now()->addSeconds(8)]);
    }
}
