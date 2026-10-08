<?php

namespace Database\Factories;

use App\Connections\AccountStatus;
use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConnectedAccount>
 */
class ConnectedAccountFactory extends Factory
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
            'provider' => Provider::Google,
            'external_account_id' => (string) fake()->unique()->numberBetween(100000000000, 999999999999),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'avatar_url' => null,
            'access_token' => 'ya29.'.Str::random(40),
            'refresh_token' => '1//'.Str::random(40),
            'scopes' => Provider::Google->scopes(),
            'disabled_capabilities' => [],
            'expires_at' => now()->addHour(),
            'status' => AccountStatus::Active,
        ];
    }

    /**
     * The access token has already expired.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    /**
     * The provider revoked access and the user has to connect it again.
     */
    public function needsReconnection(): static
    {
        return $this->state(fn (): array => ['status' => AccountStatus::NeedsReconnection]);
    }
}
