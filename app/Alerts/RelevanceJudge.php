<?php

namespace App\Alerts;

use App\Models\User;

interface RelevanceJudge
{
    /**
     * Decide whether the person should be alerted, and why.
     *
     * @throws \Throwable when it could not decide; the candidate is retried later.
     */
    public function judge(User $user, InboxCandidate $candidate): Judgement;
}
