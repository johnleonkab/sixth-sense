<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsIntentParticipant>
 */
class CommsIntentParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'intent_id' => CommsIntent::factory(),
            'person_id' => CommsPerson::factory(),
            'role' => 'requester',
        ];
    }
}
