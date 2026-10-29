<?php

namespace App\Alerts\Push;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

/**
 * Web Push as the browsers define it: a VAPID-signed, encrypted request to
 * the push service named in the subscription.
 */
class WebPushSender implements PushSender
{
    public function send(iterable $subscriptions, string $payload): bool
    {
        $public = config('services.vapid.public_key');
        $private = config('services.vapid.private_key');

        if (blank($public) || blank($private)) {
            return false;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => (string) config('services.vapid.subject'),
                'publicKey' => $public,
                'privateKey' => $private,
            ],
        ]);

        $delivered = false;
        $failed = false;

        foreach ($subscriptions as $subscription) {
            $report = $webPush->sendOneNotification($this->subscription($subscription), $payload, ['TTL' => 60 * 60 * 12]);

            if ($report->isSubscriptionExpired()) {
                $subscription->delete();
                $delivered = true;

                continue;
            }

            if ($report->isSuccess()) {
                $delivered = true;

                continue;
            }

            $failed = true;
        }

        if ($failed && ! $delivered) {
            throw new RuntimeException('Web Push delivery failed.');
        }

        return $delivered;
    }

    private function subscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->p256dh,
            'authToken' => $subscription->auth,
            'contentEncoding' => $subscription->content_encoding,
        ]);
    }
}
