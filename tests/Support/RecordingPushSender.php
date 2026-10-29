<?php

namespace Tests\Support;

use App\Alerts\Push\PushSender;
use RuntimeException;

/**
 * Records each system notification instead of calling a push service.
 */
class RecordingPushSender implements PushSender
{
    /** @var list<array{endpoint: string, payload: string}> */
    public array $sent = [];

    /** @var list<string> */
    public array $gone = [];

    public bool $fail = false;

    public function send(iterable $subscriptions, string $payload): bool
    {
        $delivered = false;
        $failed = false;

        foreach ($subscriptions as $subscription) {
            $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => $payload];

            if (in_array($subscription->endpoint, $this->gone, true)) {
                $subscription->delete();
                $delivered = true;

                continue;
            }

            if ($this->fail) {
                $failed = true;

                continue;
            }

            $delivered = true;
        }

        if ($failed && ! $delivered) {
            throw new RuntimeException('Web Push delivery failed.');
        }

        return $delivered;
    }
}
