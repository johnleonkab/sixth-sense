<?php

namespace Database\Factories\Comms;

use App\Comms\People\Relation;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsPerson>
 */
class CommsPersonFactory extends Factory
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
            'display_name' => fake()->name(),
            'relation' => Relation::Unknown,
            'importance_weight' => Relation::Unknown->weight(),
            'team' => null,
            'role' => null,
            'is_bot' => false,
        ];
    }
}
