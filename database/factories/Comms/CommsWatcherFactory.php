<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsWatcher>
 */
class CommsWatcherFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'intent_id' => CommsIntent::factory(),
            'key' => 'watch-'.fake()->unique()->numerify('####'),
            'condition_type' => 'delivery',
            'condition' => ['reply' => true, 'delivery' => true],
            'deadline_at' => now()->addDays(2),
            'attempt' => 0,
            'state' => 'active',
        ];
    }
}
