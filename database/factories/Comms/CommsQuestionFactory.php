<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsQuestion>
 */
class CommsQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => 'q-'.fake()->unique()->numerify('####'),
            'text' => fake()->sentence(),
            'context' => [],
            'options' => [],
        ];
    }
}
