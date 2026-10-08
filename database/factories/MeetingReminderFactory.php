<?php

namespace Database\Factories;

use App\Models\MeetingReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingReminder>
 */
class MeetingReminderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addMinutes(20)->startOfMinute();

        return [
            'user_id' => User::factory(),
            'event_key' => 'ev-'.fake()->unique()->numberBetween(1, 99999).'@'.$start->toIso8601String(),
            'event_ref' => '1:primary:ev',
            'title' => 'Revisión prepago',
            'starts_at' => $start,
            'remind_at' => $start->copy()->subMinutes(15),
            'lead_minutes' => 15,
            'needs_preparation' => true,
            'preparation' => 'Repasa el PRD del prepago.',
            'payload' => ['organizer' => 'Marta Ruiz', 'meeting_url' => 'https://meet.google.com/abc', 'url' => null],
        ];
    }
}
