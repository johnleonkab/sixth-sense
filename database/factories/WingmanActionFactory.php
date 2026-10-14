<?php

namespace Database\Factories;

use App\Models\WingmanAction;
use App\Models\WingmanMeeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WingmanAction>
 */
class WingmanActionFactory extends Factory
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
            'kind' => 'message',
            'title' => 'Mensaje para Marta Ruiz',
            'detail' => 'Hola Marta, te resumo lo acordado.',
            'type' => 'chat.send',
            'label' => 'Mensaje a Marta Ruiz',
            'payload' => ['message' => ['to' => 'demo:U01', 'to_label' => 'Marta Ruiz', 'text' => 'Hola Marta, te resumo lo acordado.', 'mentions' => [], 'thread_ref' => null]],
            'dedupe_key' => 'message:'.fake()->unique()->slug(2),
        ];
    }
}
