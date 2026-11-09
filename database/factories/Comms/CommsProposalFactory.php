<?php

namespace Database\Factories\Comms;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\ProposalStatus;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsProposal>
 */
class CommsProposalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => 'prop-'.fake()->unique()->numerify('####'),
            'action_class' => ActionClass::SendSlack,
            'target' => [],
            'payload' => ['text' => fake()->sentence()],
            'source_event_ids' => [],
            'status' => ProposalStatus::Pending,
        ];
    }
}
