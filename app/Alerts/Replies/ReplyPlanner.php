<?php

namespace App\Alerts\Replies;

use App\Models\User;

/**
 * The reply engine: given a message waiting for the person's answer, it
 * thinks through how to resolve it and proposes it (reply to whom, saying
 * what, and anything else to do). It only proposes: the person reviews it
 * with the voice assistant, who carries it out with confirmation.
 *
 * Any implementation fits here, including an agent of its own with access
 * to the person's knowledge.
 */
interface ReplyPlanner
{
    /**
     * @throws \Throwable when it could not plan; the alert stays without a proposal.
     */
    public function plan(User $user, AlertBrief $brief): ReplyPlan;
}
