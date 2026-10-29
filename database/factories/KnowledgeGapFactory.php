<?php

namespace Database\Factories;

use App\Knowledge\Gaps\GapKind;
use App\Knowledge\Gaps\GapStatus;
use App\Models\KnowledgeGap;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeGap>
 */
class KnowledgeGapFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subject = 'Sync '.fake()->unique()->word();

        return [
            'user_id' => User::factory(),
            'kind' => GapKind::Meeting,
            'subject_key' => fake()->uuid().'@google.com',
            'subject' => $subject,
            'question' => "¿Para qué es «{$subject}»?",
            'status' => GapStatus::Open,
            'priority' => 50,
        ];
    }

    public function answered(string $answer = 'Revisión semanal de operaciones con soporte.'): self
    {
        return $this->state(fn (): array => [
            'status' => GapStatus::Answered,
            'answer' => $answer,
            'asked_at' => now()->subDay(),
            'answered_at' => now(),
        ]);
    }
}
