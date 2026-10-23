<?php

namespace Database\Factories;

use App\Connections\Provider;
use App\Models\ConnectedAccount;
use App\Models\GithubRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GithubRepository>
 */
class GithubRepositoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = fake()->userName();
        $name = fake()->slug(2);

        return [
            'connected_account_id' => ConnectedAccount::factory()->state([
                'provider' => Provider::GitHub,
                'scopes' => Provider::GitHub->scopes(),
                'refresh_token' => null,
                'expires_at' => null,
            ]),
            'github_id' => fake()->unique()->numberBetween(1, 999999999),
            'owner' => $owner,
            'name' => $name,
            'full_name' => "{$owner}/{$name}",
            'is_private' => false,
            'default_branch' => 'main',
            'can_push' => true,
            'included' => false,
            'writes_code' => false,
        ];
    }

    /**
     * The person wants sixth-sense to use this repository, and to update its code.
     */
    public function writable(): static
    {
        return $this->state(fn (): array => [
            'included' => true,
            'writes_code' => true,
            'can_push' => true,
        ]);
    }
}
