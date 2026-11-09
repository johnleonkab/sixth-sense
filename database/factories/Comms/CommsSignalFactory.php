<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsSignal>
 */
class CommsSignalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'message_id' => CommsMessage::factory(),
            'summary' => fake()->sentence(),
            'importance' => 0,
        ];
    }
}
