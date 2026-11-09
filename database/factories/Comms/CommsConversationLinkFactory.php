<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsConversationLink>
 */
class CommsConversationLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'intent_id' => CommsIntent::factory(),
            'message_id' => CommsMessage::factory(),
            'operation' => 'open',
            'confidence' => 0.9,
            'uncertain' => false,
        ];
    }
}
