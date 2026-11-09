<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsNotification>
 */
class CommsNotificationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => 'n-'.fake()->unique()->numerify('####'),
            'kind' => 'question',
            'urgency' => 'digest',
            'source_event_ids' => [],
        ];
    }
}
