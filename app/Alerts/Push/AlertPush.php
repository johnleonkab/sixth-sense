<?php

namespace App\Alerts\Push;

use App\Models\InboxItem;

/**
 * The system notification for one important alert: who, why, and where to open it.
 */
class AlertPush
{
    public function __construct(private PushSender $sender) {}

    /**
     * Send it to every browser the person registered. False when there is
     * nowhere to send it, so the caller can try again later.
     */
    public function deliver(InboxItem $item): bool
    {
        $subscriptions = $item->user->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return false;
        }

        return $this->sender->send($subscriptions, json_encode($this->message($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Declarative Web Push. Safari shows it itself; other browsers hand the
     * same JSON to the service worker.
     *
     * @return array{web_push: int, notification: array<string, string>, mutable: bool}
     */
    public function message(InboxItem $item): array
    {
        $source = $item->source->label();

        return [
            'web_push' => 8030,
            'notification' => [
                'title' => filled($item->actor_name) ? "{$item->actor_name} · {$source}" : $source,
                'body' => $item->why !== '' ? $item->why : (string) $item->title,
                'navigate' => url('/?alert='.$item->id),
                'lang' => 'es',
                'tag' => 'alert-'.$item->id,
            ],
            'mutable' => false,
        ];
    }
}
