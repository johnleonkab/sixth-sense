<?php

namespace App\Alerts;

use App\Alerts\Push\PushSender;
use App\Models\InboxItem;
use App\Models\User;

/**
 * The alerts that need not interrupt, together: twice a day, one notice
 * with all the quiet ones still waiting, which opens as a list.
 */
class AlertDigest
{
    public function __construct(private PushSender $sender) {}

    /**
     * @return list<InboxItem> The alerts it gathered, now marked as sent in a digest.
     */
    public function send(User $user): array
    {
        $quiet = $user->inboxItems()
            ->where('status', AlertStatus::Pending)
            ->where('triage', Triage::Important)
            ->get()
            ->filter(fn (InboxItem $item): bool => $item->quiet() && ! isset($item->payload['digested_at']))
            ->values();

        if ($quiet->isEmpty()) {
            return [];
        }

        $names = $quiet->pluck('actor_name')->filter()->unique()->take(3)->implode(', ');
        $count = $quiet->count();
        $subscriptions = $user->pushSubscriptions;

        if ($subscriptions->isNotEmpty()) {
            $this->sender->send($subscriptions, json_encode([
                'web_push' => 8030,
                'notification' => [
                    'title' => $count === 1 ? '1 aviso sin prisa' : "{$count} avisos sin prisa",
                    'body' => "De {$names}. Cuando tengas un momento.",
                    'navigate' => url('/?alerts=pending'),
                    'lang' => 'es',
                    'tag' => 'alerts-digest',
                ],
                'mutable' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $quiet->each(fn (InboxItem $item) => $item->update(['payload' => [...($item->payload ?? []), 'digested_at' => now()->toIso8601String()]]));

        return $quiet->all();
    }
}
