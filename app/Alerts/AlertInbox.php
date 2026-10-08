<?php

namespace App\Alerts;

use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Jobs\PlanAlertReply;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use Illuminate\Support\Facades\Log;

/**
 * The proactive path for one account of a source: find what is new, leave
 * out the noise, ask the judge about the rest and alert about what waits for
 * the person's answer. Each alert then gets a proposal of how to answer it.
 */
class AlertInbox
{
    public function __construct(
        private InboxWatchers $watchers,
        private NoiseFilter $noise,
        private RelevanceJudge $judge,
    ) {}

    /**
     * @return list<InboxItem> Alerts raised.
     */
    public function check(ConnectedAccount $account, AlertSource $source = AlertSource::Gmail): array
    {
        $user = $account->user;
        $watcher = $this->watchers->for($source);
        $alerts = [];

        foreach ($watcher->newMessages($account) as $candidate) {
            if ($user->inboxItems()->where('source', $candidate->source)->where('external_id', $candidate->externalId)->exists()) {
                continue;
            }

            if ($reason = $this->noise->noise($user, $candidate)) {
                Log::debug("Alert skipped ({$reason}): {$candidate->externalId}");

                continue;
            }

            $judgement = $this->judge->judge($user, $candidate);

            if (! $judgement->important) {
                continue;
            }

            $attributes = [
                'external_url' => $candidate->url,
                'actor_name' => $candidate->from?->label(),
                'title' => $candidate->subject,
                'body' => mb_substr($candidate->excerpt, 0, 1000),
                'why' => $judgement->reason,
                'relevance' => $judgement->relevance,
                'payload' => array_filter([
                    'account' => $candidate->account,
                    'from' => $candidate->from?->toArray(),
                    'kind' => $candidate->labels[0] ?? null,
                ]),
                'proposal' => null,
                'received_at' => $candidate->receivedAt,
            ];

            // A new message in a conversation that already waits for an answer is the same alert, now about it.
            $open = $candidate->threadKey
                ? $user->inboxItems()->pending()->where('source', $candidate->source)->where('thread_key', $candidate->threadKey)->first()
                : null;

            if ($open !== null) {
                $open->update(['external_id' => $candidate->externalId, ...$attributes]);
                InboxItemUpdated::dispatch($open);
                PlanAlertReply::dispatch($open);
                $alerts[] = $open;

                continue;
            }

            $item = $user->inboxItems()->firstOrCreate(
                ['source' => $candidate->source, 'external_id' => $candidate->externalId],
                ['thread_key' => $candidate->threadKey, 'status' => AlertStatus::Pending, ...$attributes],
            );

            if ($item->wasRecentlyCreated) {
                InboxItemCreated::dispatch($item);
                PlanAlertReply::dispatch($item);
                $alerts[] = $item;
            }
        }

        // Only now: if anything above failed, the same messages are checked again next minute.
        $watcher->commit($account);

        return $alerts;
    }
}
