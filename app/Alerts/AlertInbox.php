<?php

namespace App\Alerts;

use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Jobs\JudgeInboxItem;
use App\Jobs\PlanAlertReply;
use App\Models\ConnectedAccount;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The proactive path for one account of a source: every new message is
 * listed at once, and judged in the background (JudgeInboxItem). Checking
 * never waits for the AI, so a slow or failing AI never stops the inbox.
 *
 * A conversation is one alert: a new message in it updates that alert
 * (with what came in since the person last dealt with it) instead of
 * raising another one.
 */
class AlertInbox
{
    public function __construct(
        private InboxWatchers $watchers,
        private NoiseFilter $noise,
    ) {}

    /**
     * @return list<InboxItem> Alerts listed or updated.
     */
    public function check(ConnectedAccount $account, AlertSource $source = AlertSource::Gmail): array
    {
        $user = $account->user;
        $watcher = $this->watchers->for($source);
        $listed = [];

        // Oldest first, so a conversation's alert ends on its latest message.
        $candidates = collect($watcher->newMessages($account))->sortBy(fn (InboxCandidate $candidate) => $candidate->receivedAt->getTimestamp() + $candidate->receivedAt->micro / 1e6);

        foreach ($candidates as $candidate) {
            if ($this->alreadyListed($user, $candidate)) {
                continue;
            }

            $noise = $this->noise->noise($user, $candidate);

            if ($noise !== null && $this->noise->hides($noise)) {
                continue;
            }

            $conversation = $this->conversationOf($user, $candidate);
            $item = $conversation !== null
                ? $this->addTo($conversation, $candidate, $noise)
                : $this->list($user, $candidate, $noise);

            if ($item !== null) {
                $listed[] = $item;
            }
        }

        $watcher->commit($account);

        return $listed;
    }

    private function alreadyListed(User $user, InboxCandidate $candidate): bool
    {
        return $user->inboxItems()
            ->where('source', $candidate->source)
            ->where(fn ($items) => $items->where('external_id', $candidate->externalId)
                ->orWhereJsonContains('payload->message_ids', $candidate->externalId))
            ->exists();
    }

    /**
     * The alert this conversation already has, if any.
     */
    private function conversationOf(User $user, InboxCandidate $candidate): ?InboxItem
    {
        if ($candidate->threadKey === null) {
            return null;
        }

        return $user->inboxItems()
            ->where('source', $candidate->source)
            ->where('thread_key', $candidate->threadKey)
            ->where(fn ($items) => $items->whereNull('resolution')->orWhere('resolution', '!=', 'superseded'))
            ->latest('id')
            ->first();
    }

    /**
     * A conversation seen for the first time: a new alert.
     */
    private function list(User $user, InboxCandidate $candidate, ?string $noise): ?InboxItem
    {
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
                    'messages' => [InboxItem::messageOf($candidate)],
                    'message_ids' => [$candidate->externalId],
                ]),
                'received_at' => $candidate->receivedAt,
            ],
        );

        if (! $item->wasRecentlyCreated) {
            return null;
        }

        InboxItemCreated::dispatch($item);

        if ($item->triage === Triage::Judging) {
            JudgeInboxItem::dispatch($item);
        }

        return $item;
    }

    /**
     * A new message in a conversation that already has an alert: the alert
     * takes it in. Once the person dealt with the alert (answered, resolved
     * or dismissed it), it opens again with only what came in since.
     */
    private function addTo(InboxItem $item, InboxCandidate $candidate, ?string $noise): InboxItem
    {
        $dealtWith = in_array($item->status, [AlertStatus::Resolved, AlertStatus::Dismissed], true);
        $messages = [...($dealtWith ? [] : $item->messages()), InboxItem::messageOf($candidate)];
        $messages = array_slice($messages, -InboxItem::MAX_MESSAGES);
        $waiting = ! $dealtWith && $item->status === AlertStatus::Pending;

        // What the judge and the reply engine read: everything new, not just the last line.
        $since = $candidate->toArray();
        $since['excerpt'] = mb_substr(collect($messages)->map(fn (array $message): string => trim(($message['from'] ?? '').': '.$message['text'], ': '))->implode("\n"), 0, 4000);

        $payload = $item->payload ?? [];
        $payload['messages'] = $messages;
        $payload['message_ids'] = array_slice([...($payload['message_ids'] ?? [$item->external_id]), $candidate->externalId], -100);
        $payload['from'] = $candidate->from?->toArray();
        $payload['candidate'] = $noise === null ? $since : ($payload['candidate'] ?? null);

        $item->fill([
            // The latest message: the one an answer must come after.
            'external_id' => $candidate->externalId,
            'external_url' => $candidate->url ?? $item->external_url,
            'actor_name' => $candidate->from?->label() ?? $item->actor_name,
            'title' => $candidate->subject,
            'body' => mb_substr($candidate->excerpt, 0, 1000),
            'received_at' => $candidate->receivedAt,
            'payload' => $payload,
        ]);

        if ($dealtWith) {
            $item->fill(['status' => AlertStatus::Notice, 'resolution' => null, 'resolved_at' => null, 'proposal' => null, 'planning' => false]);
        }

        // Still waiting for an answer: the proposal is thought again with the new message.
        // Otherwise the new messages are judged (a quiet one, like a newsletter, is not).
        $judge = ! $waiting && $noise === null;
        $plan = $waiting;

        if ($judge) {
            $item->triage = Triage::Judging;
        } elseif ($dealtWith) {
            $item->fill(['triage' => Triage::Routine, 'why' => Str::ucfirst((string) $noise)]);
        }

        if ($plan) {
            $item->planning = true;
        }

        $item->save();

        InboxItemUpdated::dispatch($item);

        if ($judge) {
            JudgeInboxItem::dispatch($item);
        }

        if ($plan) {
            PlanAlertReply::dispatch($item);
        }

        return $item;
    }
}
