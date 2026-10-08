<?php

namespace App\WorkContext;

use App\Models\User;

/**
 * Turns the person's recent activity into what they are working on.
 */
interface ContextSummarizer
{
    /**
     * @throws \Throwable when it could not summarize; the previous context is kept.
     */
    public function summarize(User $user, WorkActivity $activity): WorkDigest;
}
