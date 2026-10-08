<?php

namespace Database\Factories;

use App\Alerts\AlertSource;
use App\Models\AlertSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertSetting>
 */
class AlertSettingFactory extends Factory
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
            'enabled' => true,
        ];
    }
}
