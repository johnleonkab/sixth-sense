<?php

namespace App\Alerts\Judges;

use App\Alerts\InboxCandidate;
use App\Alerts\Judgement;
use App\Alerts\RelevanceJudge;
use App\Models\User;

/**
 * For tests and demo mode: important when the subject or text asks for
 * something urgent.
 */
class FakeRelevanceJudge implements RelevanceJudge
{
    /** @var list<InboxCandidate> */
    public array $judged = [];

    public function judge(User $user, InboxCandidate $candidate): Judgement
    {
        $this->judged[] = $candidate;

        $important = (bool) preg_match('/urgente|antes del|necesito|firma|revis/iu', $candidate->subject.' '.$candidate->excerpt);
        $who = $candidate->from?->name ?? $candidate->from?->email ?? 'Alguien';

        return new Judgement(
            important: $important,
            reason: $important ? "{$who} te pide algo: {$candidate->subject}" : 'Informativo, sin nada que hacer.',
            relevance: $important ? 80 : 10,
        );
    }
}
