<?php

namespace App\Knowledge\Gaps;

use App\Models\User;

/**
 * Asks about every candidate, with the same question. For tests and demo mode.
 */
class FakeTermJudge implements TermJudge
{
    public function judge(User $user, array $candidates): array
    {
        return array_map(fn (array $candidate): array => [
            'term' => $candidate['term'],
            'question' => "¿Qué es «{$candidate['term']}»?",
        ], $candidates);
    }
}
