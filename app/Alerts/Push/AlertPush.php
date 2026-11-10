<?php

namespace App\Alerts\Push;

use App\Models\InboxItem;
use App\Models\User;

/**
 * The system notification for one important alert. It says what the alert
 * says on screen: "who · where" as its title, then the title and the why.
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
        return $this->deliverCard($item->user, $item->toAlert());
    }

    /**
     * @param  array<string, mixed>  $alert  An alert as the screen gets it.
     */
    public function deliverCard(User $user, array $alert): bool
    {
        $subscriptions = $user->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return false;
        }

        return $this->sender->send($subscriptions, json_encode(self::forAlert($alert), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{web_push: int, notification: array<string, string>, mutable: bool}
     */
    public function message(InboxItem $item): array
    {
        return self::forAlert($item->toAlert());
    }

    /**
     * Declarative Web Push. Safari shows it itself; other browsers hand the
     * same JSON to the service worker.
     *
     * @param  array<string, mixed>  $alert
     * @return array{web_push: int, notification: array<string, string>, mutable: bool}
     */
    public static function forAlert(array $alert): array
    {
        $source = (string) $alert['source_label'];
        $actor = (string) ($alert['actor_name'] ?? '');
        $count = count($alert['messages'] ?? []);
        $title = trim((string) $alert['title']).($count > 1 ? " · {$count} mensajes" : '');

        return [
            'web_push' => 8030,
            'notification' => [
                'title' => $actor !== '' ? "{$actor} · {$source}" : $source,
                'body' => trim($title."\n".trim((string) ($alert['why'] ?? ''))),
                'navigate' => url('/?alert='.$alert['id']),
                'lang' => 'es',
                'tag' => 'alert-'.$alert['id'],
            ],
            'mutable' => false,
        ];
    }
}
