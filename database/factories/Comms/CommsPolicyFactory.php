<?php

namespace Database\Factories\Comms;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\PolicyOutcome;
use App\Models\Comms\CommsPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommsPolicy>
 */
class CommsPolicyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action_class' => ActionClass::SendSlack,
            'conditions' => [],
            'outcome' => PolicyOutcome::Ask,
        ];
    }
}
