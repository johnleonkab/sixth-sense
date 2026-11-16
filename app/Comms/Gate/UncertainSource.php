<?php

namespace App\Comms\Gate;

use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsProposal;
use App\Models\User;

/**
 * A draft built on an uncertain link is asked about, and rebuilt when that
 * message moves.
 */
class UncertainSource
{
    public function __construct(private Gate $gate) {}

    public function moved(User $user, CommsMessage $message, ?CommsIntent $intent): void
    {
        $proposals = CommsProposal::query()
            ->whereBelongsTo($user)
            ->whereIn('status', [ProposalStatus::Pending, ProposalStatus::Edited])
            ->orderBy('id')
            ->get()
            ->filter(fn (CommsProposal $proposal): bool => $this->cites($proposal, $message))
            ->values();

        foreach ($proposals as $proposal) {
            $this->gate->reject($proposal);

            if ($intent === null || $message->event_id === null) {
                continue;
            }

            $payload = is_array($proposal->payload) ? $proposal->payload : [];
            $payload['text'] = (string) $message->text;

            $this->gate->submit(
                user: $user,
                class: $proposal->action_class,
                target: is_array($proposal->target) ? $proposal->target : [],
                payload: $payload,
                intent: $intent,
                sourceEventIds: [(int) $message->event_id],
                userInitiated: (bool) $proposal->user_initiated,
                statesProductFacts: (bool) $proposal->states_product_facts,
                commitsDate: (bool) $proposal->commits_date,
                verification: is_array($proposal->verification) ? $proposal->verification : null,
                allows: is_array($proposal->allows) ? $proposal->allows : null,
                notify: false,
            );
        }
    }

    private function cites(CommsProposal $proposal, CommsMessage $message): bool
    {
        $ids = $proposal->uncertain_source_message_ids;

        if (! is_array($ids)) {
            return false;
        }

        return in_array($message->id, array_map(intval(...), $ids), true);
    }
}
