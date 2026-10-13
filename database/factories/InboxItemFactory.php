<?php

namespace Database\Factories;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxItem>
 */
class InboxItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => AlertSource::Gmail,
            'external_id' => '1.'.fake()->unique()->bothify('????????????'),
            'actor_name' => fake()->name(),
            'title' => fake()->sentence(4),
            'why' => 'Te pide revisar algo antes del viernes.',
            'relevance' => 80,
            'status' => AlertStatus::Pending,
            'triage' => Triage::Important,
            'payload' => ['account' => 'jhon@work.com'],
            'received_at' => now(),
        ];
    }
}
