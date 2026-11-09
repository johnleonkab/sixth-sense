<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsOwnedArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsOwnedArea>
 */
class CommsOwnedAreaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'product',
            'ref' => fake()->unique()->slug(),
            'name' => fake()->words(2, true),
            'keywords' => [],
        ];
    }
}
