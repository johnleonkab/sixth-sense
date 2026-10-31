<?php

namespace App\Alerts\Outcomes;

use App\Models\InboxItem;
use App\Models\PendingAction;
use App\Models\ProposalOutcome;

/**
 * Writes down what happened to what the system proposed for an alert: the
 * only way into proposal_outcomes. Each confirmed action is one row (sent as
 * proposed, or edited and by how much); closing the alert without one —
 * discarded, resolved, expired — is one row for the alert.
 */
class ProposalOutcomes
{
    /**
     * A proposed action got its "sí". Edited when what goes out differs from
     * what was proposed.
     */
    public function confirmed(PendingAction $action): ?ProposalOutcome
    {
        $alert = $this->alertOf($action);

        if ($alert === null) {
            return null;
        }

        $proposed = $action->payload['proposed_text'] ?? null;
        $sent = $this->textOf($action);
        $edit = is_string($proposed) && $sent !== null ? self::editSize($proposed, $sent) : 0;

        return $this->write($alert, $edit > 0 ? ProposalOutcome::EDITED : ProposalOutcome::SENT, [
            'pending_action_id' => $action->id,
            'action_type' => $action->type,
            'class' => $this->classOf($alert, $action),
            'edit_size' => $edit,
            'detail' => $edit > 0 ? ['proposed' => mb_substr((string) $proposed, 0, 2000), 'sent' => mb_substr((string) $sent, 0, 2000)] : null,
        ]);
    }

    /**
     * Undone within the window: what was sent never went.
     */
    public function undone(PendingAction $action): void
    {
        ProposalOutcome::query()->where('pending_action_id', $action->id)->update(['outcome' => ProposalOutcome::UNDONE]);
    }

    /**
     * @param  ?string  $reason  One of ProposalOutcome::REASONS, or null when none was chosen.
     */
    public function discarded(InboxItem $alert, ?string $reason = null): ProposalOutcome
    {
        return $this->write($alert, ProposalOutcome::DISCARDED, [
            'class' => $this->classOf($alert),
            'reason' => in_array($reason, ProposalOutcome::REASONS, true) ? $reason : null,
        ]);
    }

    /**
     * Closed as resolved: by the person, or on its own because they answered
     * elsewhere. Nothing is written when something was already sent from here.
     *
     * @param  string  $how  marked_done, replied_outside…
     */
    public function resolved(InboxItem $alert, string $how): ?ProposalOutcome
    {
        if ($this->sentFromHere($alert)) {
            return null;
        }

        return $this->write($alert, ProposalOutcome::RESOLVED, ['class' => $this->classOf($alert), 'reason' => $how]);
    }

    /**
     * It stopped making sense before the person acted: replaced by a newer
     * message, someone else answered, the call already happened.
     */
    public function expired(InboxItem $alert, string $why): ?ProposalOutcome
    {
        if ($this->sentFromHere($alert)) {
            return null;
        }

        return $this->write($alert, ProposalOutcome::EXPIRED, ['class' => $this->classOf($alert), 'reason' => $why]);
    }

    /**
     * The alert is open again: what was recorded for its closing no longer holds.
     */
    public function reopened(InboxItem $alert): void
    {
        $alert->outcomes()->whereIn('outcome', [ProposalOutcome::DISCARDED, ProposalOutcome::RESOLVED, ProposalOutcome::EXPIRED])->delete();
    }

    /**
     * How much an edit changed, in characters.
     */
    public static function editSize(string $proposed, string $sent): int
    {
        $proposed = trim($proposed);
        $sent = trim($sent);

        if ($proposed === $sent) {
            return 0;
        }

        // levenshtein() is costly on long texts: the common part is enough there.
        if (strlen($proposed) > 1000 || strlen($sent) > 1000) {
            similar_text($proposed, $sent, $percent);

            return (int) round(max(mb_strlen($proposed), mb_strlen($sent)) * (100 - $percent) / 100);
        }

        return levenshtein($proposed, $sent);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(InboxItem $alert, string $outcome, array $values): ProposalOutcome
    {
        return $alert->outcomes()->create([
            'user_id' => $alert->user_id,
            'outcome' => $outcome,
            'confidence' => $this->confidenceOf($alert),
            'seconds_to_outcome' => $alert->received_at ? max(0, (int) $alert->received_at->diffInSeconds(now())) : null,
            ...$values,
        ]);
    }

    private function sentFromHere(InboxItem $alert): bool
    {
        return $alert->outcomes()->whereIn('outcome', [ProposalOutcome::SENT, ProposalOutcome::EDITED])->exists();
    }

    private function alertOf(PendingAction $action): ?InboxItem
    {
        $id = $action->payload['inbox_item_id'] ?? null;

        return is_int($id) ? $action->user->inboxItems()->find($id) : null;
    }

    private function textOf(PendingAction $action): ?string
    {
        return $action->payload['message']['text'] ?? $action->payload['email']['body'] ?? null;
    }

    /**
     * What kind of action this was: a call, a task, a question or a reply —
     * from the alert's classified actions, or from the action itself.
     */
    private function classOf(InboxItem $alert, ?PendingAction $action = null): ?string
    {
        if ($action !== null && $action->type === 'calendar.change') {
            return ($action->payload['event']['attendees'] ?? []) === [] ? 'task' : 'call';
        }

        $classes = array_column($alert->proposal['actions'] ?? [], 'class');

        return $classes[0] ?? (($alert->proposal['reply'] ?? null) || ($alert->proposal['reaction'] ?? null) ? 'reply' : null);
    }

    private function confidenceOf(InboxItem $alert): ?string
    {
        $levels = array_column($alert->proposal['actions'] ?? [], 'confidence');

        return $levels === [] ? null : (in_array('doubt', $levels, true) ? 'doubt' : 'sure');
    }
}
