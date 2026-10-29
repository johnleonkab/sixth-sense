<?php

namespace App\Knowledge\Gaps;

use App\Models\User;

/**
 * Decides which repeated terms are worth asking about: internal project
 * names and acronyms whose meaning is not obvious, not everyday ones.
 */
interface TermJudge
{
    /**
     * @param  list<array{term: string, documents: int, examples: list<string>}>  $candidates
     * @return list<array{term: string, question: string}> Only the ones worth asking.
     */
    public function judge(User $user, array $candidates): array;
}
