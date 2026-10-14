<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WingmanMeeting;
use App\Wingman\MeetingStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WingmanMeeting>
 */
class WingmanMeetingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $starts = now()->addHour()->startOfHour()->toImmutable();
        $code = 'abc-'.fake()->unique()->lexify('????').'-xyz';

        return [
            'user_id' => User::factory(),
            'event_key' => fake()->uuid().'@'.$starts->toIso8601String(),
            'event_ref' => 'demo:primary:'.fake()->uuid(),
            'meeting_code' => $code,
            'meeting_url' => "https://meet.google.com/{$code}",
            'title' => 'Revisión flujo de prepago',
            'account' => 'jhon@cm.com',
            'organizer' => 'Marta Ruiz',
            'starts_at' => $starts,
            'ends_at' => $starts->addMinutes(45),
            'attendees' => [['name' => 'Marta Ruiz', 'email' => 'marta@cm.com', 'response' => 'accepted']],
            'description' => null,
            'status' => MeetingStatus::Scheduled,
            'context' => null,
            'context_prepared_at' => null,
        ];
    }
}
