<?php

namespace Database\Factories;

use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WingmanSegment>
 */
class WingmanSegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wingman_meeting_id' => WingmanMeeting::factory(),
            'client_id' => fake()->uuid(),
            'speaker' => 'Marta Ruiz',
            'is_me' => false,
            'text' => '¿Cómo va el reembolso parcial?',
            'final' => true,
            'spoken_at' => now(),
        ];
    }
}
