<?php

namespace App\Jobs;

use App\Alerts\AlertStatus;
use App\Alerts\InboxCandidate;
use App\Alerts\Judgement;
use App\Alerts\Judges\RuleRelevanceJudge;
use App\Alerts\RelevanceJudge;
use App\Alerts\Triage;
use App\Events\InboxItemUpdated;
use App\Models\InboxItem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Decides, in the background, whether a message that has just been listed
 * waits for the person's answer. If it does, it becomes a pending alert (the
 * browser rings) and the reply engine is asked for a proposal. When the AI
 * can not answer after a few tries, simple rules decide.
 */
class JudgeInboxItem implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 90;

    /**
     * The AI may be over its rate limit: wait a little longer each time.
     *
     * @var list<int>
     */
    public array $backoff = [20, 60, 180];

    public int $uniqueFor = 900;

    public function __construct(public InboxItem $item) {}

    public function uniqueId(): string
    {
        return (string) $this->item->id;
    }

    public function handle(RelevanceJudge $judge): void
    {
        $item = $this->item->fresh();

        if ($item === null || $item->triage !== Triage::Judging) {
            return;
        }

        $this->apply($item, $judge->judge($item->user, InboxCandidate::fromArray($item->payload['candidate'])));
    }

    /**
     * Out of tries: the message is still decided, by rules.
     */
    public function failed(?Throwable $exception): void
    {
        $item = $this->item->fresh();

        if ($item !== null && $item->triage === Triage::Judging) {
            $this->apply($item, app(RuleRelevanceJudge::class)->judge($item->user, InboxCandidate::fromArray($item->payload['candidate'])));
        }
    }

    private function apply(InboxItem $item, Judgement $judgement): void
    {
        // Dismissed (or resolved) while it was being judged: the person decided, the judgement only says why.
        if (in_array($item->status, [AlertStatus::Resolved, AlertStatus::Dismissed], true)) {
            $item->update(['triage' => $judgement->important ? Triage::Important : Triage::Routine, 'why' => $judgement->reason, 'relevance' => $judgement->relevance]);
            InboxItemUpdated::dispatch($item);

            return;
        }

        if (! $judgement->important) {
            $item->update(['triage' => Triage::Routine, 'why' => $judgement->reason, 'relevance' => $judgement->relevance]);
            InboxItemUpdated::dispatch($item);

            return;
        }

        $item->update([
            'triage' => Triage::Important,
            'status' => AlertStatus::Pending,
            'why' => $judgement->reason,
            'relevance' => $judgement->relevance,
            'planning' => true,
        ]);

        // A newer message in a conversation that already waited for an answer takes its place.
        if ($item->thread_key !== null) {
            $item->user->inboxItems()->pending()
                ->where('source', $item->source)
                ->where('thread_key', $item->thread_key)
                ->whereKeyNot($item->id)
                ->get()
                ->each(function (InboxItem $older): void {
                    $older->close(AlertStatus::Dismissed, 'superseded');
                    InboxItemUpdated::dispatch($older);
                });
        }

        InboxItemUpdated::dispatch($item);
        PlanAlertReply::dispatch($item);
    }
}
