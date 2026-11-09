<?php

namespace Database\Factories\Comms;

use App\Models\Comms\CommsPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsPreference>
 */
class CommsPreferenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'timezone' => 'Europe/Madrid',
            'locale' => 'es',
            'work_start' => '09:00',
            'work_end' => '19:00',
            'work_days' => [1, 2, 3, 4, 5],
        ];
    }
}
