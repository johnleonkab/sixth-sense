<?php

namespace Database\Factories;

use App\Models\WingmanInsight;
use App\Models\WingmanMeeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WingmanInsight>
 */
class WingmanInsightFactory extends Factory
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
            'kind' => 'issue',
            'title' => 'CPD-412 · As a diner, I want to see the total',
            'body' => 'Sigue en To Do, bloqueado por CPD-405.',
            'why' => 'Marta preguntó por el reembolso parcial.',
            'quote' => '¿Cómo va el reembolso parcial?',
            'links' => [],
            'action' => null,
            'importance' => 'normal',
            'dedupe_key' => 'issue:'.fake()->unique()->numerify('CPD-###'),
        ];
    }
}
