<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsScope>
 */
class CommsScopeFactory extends Factory
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
            'slack_channel_ids' => [],
        ];
    }
}
