<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.fake()->unique()->bothify('??????????'),
            'p256dh' => fake()->regexify('[A-Za-z0-9_-]{80}'),
            'auth' => fake()->regexify('[A-Za-z0-9_-]{22}'),
            'content_encoding' => 'aes128gcm',
        ];
    }
}
