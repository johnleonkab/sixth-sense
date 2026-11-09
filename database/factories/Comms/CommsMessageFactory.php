<?php

namespace Database\Factories\Comms;

use App\Comms\Events\Source;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsMessage>
 */
class CommsMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fn (array $attributes): int => CommsPerson::query()->find($attributes['author_person_id'])->user_id,
            'event_id' => fn (array $attributes): int => CommsEvent::factory()->create([
                'user_id' => CommsPerson::query()->find($attributes['author_person_id'])->user_id,
            ])->id,
            'source' => Source::Slack,
            'external_id' => fake()->unique()->bothify('msg-????????'),
            'channel_ref' => 'C1',
            'thread_ref' => null,
            'author_person_id' => CommsPerson::factory(),
            'recipient_person_ids' => [],
            'direction' => 'in',
            'text' => fake()->sentence(),
            'links' => [],
            'attachments' => [],
            'flags' => [
                'sensitive' => false,
                'urgent' => false,
                'automated' => false,
                'noise' => false,
                'cc_only' => false,
            ],
            'occurred_at' => now(),
        ];
    }
}
