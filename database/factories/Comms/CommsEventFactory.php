<?php

namespace Database\Factories\Comms;

use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsEvent>
 */
class CommsEventFactory extends Factory
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
            'type' => EventType::MessageReceived,
            'source' => Source::Slack,
            'external_id' => fake()->unique()->bothify('msg-????????'),
            'revision' => '',
            'payload' => ['text' => fake()->sentence()],
            'normalized' => ['text' => fake()->sentence()],
            'occurred_at' => now(),
        ];
    }
}
