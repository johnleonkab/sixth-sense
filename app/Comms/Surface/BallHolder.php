<?php

namespace App\Comms\Surface;

use Carbon\CarbonInterface;

/**
 * Which home group an intent belongs to (proposal 7.2, agent-definitions
 * 1.4). Derived, never stored: the stored states stay open, settled, stale
 * and expired. The caller loads the facts; this decides.
 *
 * Order matters: anything waiting for the user wins over work in progress,
 * which wins over waiting on others.
 */
final class BallHolder
{
    /**
     * @param  array{
     *     state: string,
     *     settled_at: ?CarbonInterface,
     *     possible_ask: bool,
     *     snoozed_until: ?CarbonInterface,
     *     user_is_responder: bool,
     *     user_is_requester: bool,
     *     pending_proposals: int,
     *     open_questions: int,
     *     executed_actions: int,
     *     active_runs: int,
     *     waiting_watchers: int,
     * }  $facts
     */
    public static function group(array $facts, CarbonInterface $now): BallGroup
    {
        if ($facts['state'] === 'settled') {
            return $facts['settled_at']?->isSameDay($now) ? BallGroup::DoneToday : BallGroup::Hidden;
        }

        if ($facts['state'] === 'expired') {
            return BallGroup::Hidden;
        }

        if ($facts['state'] === 'stale') {
            return BallGroup::Stale;
        }

        if ($facts['possible_ask']) {
            return BallGroup::PossibleAsk;
        }

        if ($facts['snoozed_until']?->isAfter($now)) {
            return BallGroup::Hidden;
        }

        if ($facts['pending_proposals'] > 0 || $facts['open_questions'] > 0) {
            return BallGroup::NeedsYou;
        }

        if ($facts['active_runs'] > 0) {
            return BallGroup::SystemWorking;
        }

        if ($facts['user_is_responder'] && $facts['executed_actions'] === 0) {
            return BallGroup::NeedsYou;
        }

        if ($facts['user_is_requester'] || $facts['waiting_watchers'] > 0 || $facts['executed_actions'] > 0) {
            return BallGroup::WaitingOnOthers;
        }

        return BallGroup::NeedsYou;
    }
}
