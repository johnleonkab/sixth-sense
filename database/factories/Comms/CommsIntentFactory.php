<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsIntent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsIntent>
 */
class CommsIntentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => 'evt-'.fake()->unique()->numerify('####'),
            'what' => fake()->sentence(),
            'state' => 'open',
            'importance' => 0,
            'flags' => [],
        ];
    }
}
