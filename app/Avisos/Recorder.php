<?php

namespace App\Avisos;

use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Events\InboxItemCreated;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Stores one relevant aviso. The same external id for a user and source
 * is stored once.
 */
class Recorder
{
    public function store(User $user, Candidate $candidate, Decision $decision): ?InboxItem
    {
        if (! $decision->relevant || $decision->why === '') {
            return null;
        }

        $item = $user->inboxItems()->firstOrCreate(
            [
                'source' => $candidate->source,
                'external_id' => $candidate->externalId,
            ],
            [
                'thread_key' => $candidate->threadKey,
                'external_url' => $candidate->url,
                'actor_name' => $candidate->actorName,
                'title' => Str::limit($candidate->title, 240, '…'),
                'body' => $candidate->body,
                'why' => $decision->why,
                'relevance' => $decision->score,
                'status' => AlertStatus::Pending,
                'triage' => Triage::Important,
                'planning' => false,
                'payload' => [
                    'account' => $candidate->accountEmail,
                    'messages' => [[
                        'id' => $candidate->externalId,
                        'from' => $candidate->actorName,
                        'text' => mb_substr($candidate->body, 0, 1000),
                        'at' => $candidate->receivedAt->toIso8601String(),
                    ]],
                ],
                'received_at' => $candidate->receivedAt,
            ],
        );

        if (! $item->wasRecentlyCreated) {
            return null;
        }

        InboxItemCreated::dispatch($item);

        return $item;
    }
}
