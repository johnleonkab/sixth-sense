<?php

namespace Database\Factories\Comms;

use App\Comms\Events\Source;
use App\Models\Comms\CommsIdentity;
use App\Models\Comms\CommsPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsIdentity>
 */
class CommsIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (array $attributes): int => CommsPerson::query()->find($attributes['person_id'])->user_id,
            'person_id' => CommsPerson::factory(),
            'source' => Source::Slack,
            'external_id' => fake()->unique()->bothify('U????????'),
            'email' => fake()->unique()->safeEmail(),
            'handle' => fake()->userName(),
        ];
    }
}
