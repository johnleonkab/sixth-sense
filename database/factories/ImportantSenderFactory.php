<?php

namespace Database\Factories;

use App\Models\ImportantSender;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportantSender>
 */
class ImportantSenderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
        ];
    }
}
