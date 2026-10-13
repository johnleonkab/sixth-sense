<?php

namespace App\Alerts;

use App\Events\InboxItemCreated;
use App\Jobs\JudgeInboxItem;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use Illuminate\Support\Str;

/**
 * The proactive path for one account of a source: every new message is
 * listed at once, and judged in the background (JudgeInboxItem). Checking
 * never waits for the AI, so a slow or failing AI never stops the inbox.
 */
class AlertInbox
{
    public function __construct(
        private InboxWatchers $watchers,
        private NoiseFilter $noise,
    ) {}

    /**
     * @return list<InboxItem> Messages listed.
     */
    public function check(ConnectedAccount $account, AlertSource $source = AlertSource::Gmail): array
    {
        $user = $account->user;
        $watcher = $this->watchers->for($source);
        $listed = [];

        foreach ($watcher->newMessages($account) as $candidate) {
            if ($user->inboxItems()->where('source', $candidate->source)->where('external_id', $candidate->externalId)->exists()) {
                continue;
            }

            $noise = $this->noise->noise($user, $candidate);

            if ($noise !== null && $this->noise->hides($noise)) {
                continue;
            }

            $item = $user->inboxItems()->firstOrCreate(
                ['source' => $candidate->source, 'external_id' => $candidate->externalId],
                [
                    'thread_key' => $candidate->threadKey,
                    'external_url' => $candidate->url,
                    'actor_name' => $candidate->from?->label(),
                    'title' => $candidate->subject,
                    'body' => mb_substr($candidate->excerpt, 0, 1000),
                    // Newsletters, promotions and automatic mail are listed quietly, without asking the AI.
                    'why' => $noise !== null ? Str::ucfirst($noise) : '',
                    'relevance' => 0,
                    'status' => AlertStatus::Notice,
                    'triage' => $noise !== null ? Triage::Routine : Triage::Judging,
                    'payload' => array_filter([
                        'account' => $candidate->account,
                        'from' => $candidate->from?->toArray(),
                        'kind' => $candidate->labels[0] ?? null,
                        'candidate' => $noise === null ? $candidate->toArray() : null,
                    ]),
                    'received_at' => $candidate->receivedAt,
                ],
            );

            if (! $item->wasRecentlyCreated) {
                continue;
            }

            InboxItemCreated::dispatch($item);

            if ($item->triage === Triage::Judging) {
                JudgeInboxItem::dispatch($item);
            }

            $listed[] = $item;
        }

        $watcher->commit($account);

        return $listed;
    }
}
