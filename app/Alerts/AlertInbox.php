<?php

namespace App\Alerts;

use App\Jobs\AssignIntent;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use App\Models\SeenMessage;
use App\Models\User;

/**
 * The proactive path for one account of a source. A message is taken once.
 * Newsletters and the person's own mail are dropped. Everything else is
 * given to an intent in the background: the same ask joins the alert that
 * already exists, and a new alert is opened only when the person has to answer.
 */
class AlertInbox
{
    public function __construct(
        private InboxWatchers $watchers,
        private NoiseFilter $noise,
    ) {}

    /**
     * @return list<InboxItem> Alerts opened or updated by this check.
     */
    public function check(ConnectedAccount $account, AlertSource $source = AlertSource::Gmail): array
    {
        $user = $account->user;
        $watcher = $this->watchers->for($source);
        $listed = [];
        $batch = [];

        // Oldest first, so a conversation's alert ends on its latest message.
        $candidates = collect($watcher->newMessages($account))->sortBy(fn (InboxCandidate $candidate) => $candidate->receivedAt->getTimestamp() + $candidate->receivedAt->micro / 1e6);

        foreach ($candidates as $candidate) {
            if ($this->alreadySeen($user, $candidate)) {
                continue;
            }

            $this->remember($user, $candidate);
            $noise = $this->noise->noise($user, $candidate);

            if ($noise !== null) {
                continue;
            }

            $thread = $candidate->threadKey;
            $earlier = $thread === null ? [] : ($batch[$thread] ?? []);
            AssignIntent::dispatch($user->id, $candidate->toArray(), $earlier);

            if ($thread !== null) {
                $batch[$thread][] = InboxItem::messageOf($candidate);
            }

            $item = $this->holding($user, $candidate->externalId);

            if ($item !== null) {
                $listed[] = $item;
            }
        }

        $watcher->commit($account);

        return $listed;
    }

    private function alreadySeen(User $user, InboxCandidate $candidate): bool
    {
        return SeenMessage::query()
            ->whereBelongsTo($user)
            ->where('source', $candidate->source)
            ->where('external_id', $candidate->externalId)
            ->exists();
    }

    private function remember(User $user, InboxCandidate $candidate): void
    {
        SeenMessage::query()->firstOrCreate([
            'user_id' => $user->id,
            'source' => $candidate->source,
            'external_id' => $candidate->externalId,
        ]);
    }

    private function holding(User $user, string $externalId): ?InboxItem
    {
        return $user->inboxItems()
            ->where(fn ($items) => $items->where('external_id', $externalId)->orWhereJsonContains('payload->message_ids', $externalId))
            ->first();
    }
}
