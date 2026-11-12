<?php

namespace App\Comms\Gate;

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Notify\Notifier;
use App\Comms\Playbooks\Runs;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPolicy;
use App\Models\Comms\CommsProposal;
use App\Models\PendingAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The only path from a proposal to a write. Policy defaults to ask.
 */
class Gate
{
    public function __construct(
        private HardDenies $denies,
        private Actions $actions,
        private Notifier $notifier,
        private EventLog $log,
        private Runs $runs,
        private CalendarUndo $calendarUndo,
    ) {}

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $payload
     * @param  list<int>  $sourceEventIds
     * @param  array<string, mixed>|null  $verification
     * @param  list<string>|null  $allows
     */
    public function submit(
        User $user,
        ActionClass $class,
        array $target,
        array $payload,
        ?CommsIntent $intent = null,
        array $sourceEventIds = [],
        bool $userInitiated = false,
        bool $statesProductFacts = false,
        bool $commitsDate = false,
        ?array $verification = null,
        ?array $allows = null,
        bool $notify = true,
    ): CommsProposal {
        $proposal = CommsProposal::query()->create([
            'user_id' => $user->id,
            'key' => 'prop:'.Str::uuid(),
            'intent_id' => $intent?->id,
            'action_class' => $class,
            'target' => $target,
            'payload' => $payload,
            'source_event_ids' => $sourceEventIds,
            'verification' => $verification,
            'allows' => $allows,
            'states_product_facts' => $statesProductFacts,
            'commits_date' => $commitsDate,
            'user_initiated' => $userInitiated,
            'status' => ProposalStatus::Pending,
        ]);

        return $this->decide($proposal, $notify);
    }

    public function decide(CommsProposal $proposal, bool $notify = true): CommsProposal
    {
        $user = $proposal->user;

        if ($user === null) {
            return $proposal;
        }

        $reason = $this->denies->reason($user, $proposal);

        if ($reason !== null) {
            return $this->deny($user, $proposal, $reason, $notify);
        }

        $outcome = $this->policy($user, $proposal->action_class);
        $proposal->policy_outcome = $outcome;

        if ($outcome === PolicyOutcome::Deny) {
            return $this->deny($user, $proposal, 'policy', $notify);
        }

        if ($outcome === PolicyOutcome::Allow) {
            $proposal->status = ProposalStatus::Approved;
            $proposal->decided_at = now();
            $proposal->save();
            $this->runs->approved($proposal);
            $this->arm($proposal);

            if ($notify) {
                $this->notify($user, $proposal, 'now');
            }

            return $proposal->refresh();
        }

        $proposal->save();

        if ($notify) {
            $proposal->loadMissing('intent');
            $this->notify($user, $proposal, $this->notifier->urgencyForProposal($user, $proposal->intent));
        }

        return $proposal->refresh();
    }

    public function approve(CommsProposal $proposal): ?PendingAction
    {
        $existing = $proposal->pendingAction;

        if ($existing !== null) {
            return $existing->status === ActionStatus::Failed ? $this->retry($proposal, $existing) : $existing;
        }

        if (! in_array($proposal->status, [ProposalStatus::Pending, ProposalStatus::Edited], true)) {
            return null;
        }

        $proposal->status = ProposalStatus::Approved;
        $proposal->decided_at = now();
        $proposal->save();
        $this->runs->approved($proposal);

        return $this->arm($proposal);
    }

    public function reject(CommsProposal $proposal): CommsProposal
    {
        if ($proposal->pendingAction !== null) {
            return $proposal;
        }

        if (! in_array($proposal->status, [ProposalStatus::Pending, ProposalStatus::Edited], true)) {
            return $proposal;
        }

        $proposal->status = ProposalStatus::Rejected;
        $proposal->decided_at = now();
        $proposal->save();

        return $proposal;
    }

    public function edit(CommsProposal $proposal, string $text): CommsProposal
    {
        if (! in_array($proposal->status, [ProposalStatus::Pending, ProposalStatus::Edited], true)) {
            return $proposal;
        }

        $payload = $proposal->payload;
        $payload['text'] = $text;
        $proposal->payload = $payload;
        $proposal->status = ProposalStatus::Edited;
        $proposal->save();

        return $proposal;
    }

    public function undo(CommsProposal $proposal): CommsProposal
    {
        $action = $proposal->pendingAction;
        $user = $proposal->user;

        if ($action === null || $user === null) {
            return $proposal;
        }

        if ($action->status === ActionStatus::Executed && $this->calendarUndo->open($proposal)) {
            $this->calendarUndo->delete($proposal);
            $proposal->status = ProposalStatus::Undone;
            $proposal->save();
            $this->undone($user, $proposal);

            return $proposal->refresh();
        }

        if ($action->status !== ActionStatus::Confirmed) {
            return $proposal;
        }

        $this->actions->undo($action);
        $proposal->status = ProposalStatus::Undone;
        $proposal->save();
        $this->undone($user, $proposal);

        return $proposal->refresh();
    }

    private function undone(User $user, CommsProposal $proposal): void
    {
        $this->log->append(
            user: $user,
            type: EventType::ActionUndone,
            source: Source::Gate,
            externalId: 'undo:'.$proposal->key,
            payload: null,
            normalized: ['proposal_id' => $proposal->id],
            causation: null,
            occurredAt: CarbonImmutable::now(),
            revision: (string) Str::uuid(),
        );
    }

    /**
     * The send failed. The same action goes again, with the text as it is now.
     */
    private function retry(CommsProposal $proposal, PendingAction $action): PendingAction
    {
        $proposal->status = ProposalStatus::Approved;
        $proposal->deny_reason = null;
        $proposal->save();

        $action->update([
            'status' => ActionStatus::Pending,
            'label' => mb_substr((string) ($proposal->payload['text'] ?? $proposal->action_class->value), 0, 240),
            'payload' => $proposal->payload,
            'error' => null,
            'result' => null,
            'executed_at' => null,
            'undo_until' => null,
            'expires_at' => now()->addHours(72),
        ]);

        return $this->actions->confirm($action);
    }

    private function arm(CommsProposal $proposal): PendingAction
    {
        $user = $proposal->user;

        try {
            $action = $user->pendingActions()->create([
                'type' => 'comms.'.$proposal->action_class->value,
                'label' => mb_substr((string) ($proposal->payload['text'] ?? $proposal->action_class->value), 0, 240),
                'payload' => $proposal->payload,
                'status' => ActionStatus::Pending,
                'expires_at' => now()->addHours(72),
                'proposal_id' => $proposal->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $proposal->pendingAction()->firstOrFail();
        }

        return $this->actions->confirm($action);
    }

    private function deny(User $user, CommsProposal $proposal, string $reason, bool $notify = true): CommsProposal
    {
        $proposal->policy_outcome = PolicyOutcome::Deny;
        $proposal->deny_reason = $reason;
        $proposal->status = ProposalStatus::Rejected;
        $proposal->decided_at = now();
        $proposal->save();

        if ($notify) {
            $this->notify($user, $proposal, 'digest');
        }

        return $proposal->refresh();
    }

    private function policy(User $user, ActionClass $class): PolicyOutcome
    {
        $rules = CommsPolicy::query()->whereBelongsTo($user)->orderBy('id')->get();

        if ($rules->isEmpty()) {
            foreach (ActionClass::cases() as $case) {
                CommsPolicy::query()->create([
                    'user_id' => $user->id,
                    'action_class' => $case,
                    'conditions' => [],
                    'outcome' => PolicyOutcome::Ask,
                ]);
            }

            return PolicyOutcome::Ask;
        }

        foreach ($rules as $rule) {
            if ($rule->action_class !== $class) {
                continue;
            }

            $conditions = $rule->conditions;

            if ($conditions === [] || $conditions === null) {
                return $rule->outcome;
            }
        }

        return PolicyOutcome::Ask;
    }

    private function notify(User $user, CommsProposal $proposal, string $urgency): void
    {
        $this->notifier->store(
            $user,
            'proposal',
            $urgency,
            'proposal',
            $proposal->id,
            'n:proposal:'.$proposal->id,
            $proposal->source_event_ids,
        );
    }
}
