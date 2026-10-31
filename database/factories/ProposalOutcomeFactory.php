<?php

namespace Database\Factories;

use App\Models\ProposalOutcome;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalOutcome>
 */
class ProposalOutcomeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'class' => 'reply',
            'action_type' => 'chat.send',
            'outcome' => ProposalOutcome::SENT,
            'seconds_to_outcome' => 600,
        ];
    }
}
