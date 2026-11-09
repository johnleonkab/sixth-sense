<?php

namespace App\Comms\Ingestion;

use App\Connectors\Slack\SlackApi;
use App\Models\ConnectedAccount;

/**
 * Slack methods ingestion is allowed to call. Anything else is refused
 * before a request is made.
 */
final class ReadOnlySlackClient
{
    private const ALLOWED = [
        'conversations.history',
        'conversations.replies',
        'users.info',
        'conversations.info',
    ];

    public function __construct(private SlackApi $api) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function call(ConnectedAccount $account, string $method, array $parameters = []): array
    {
        if (! in_array($method, self::ALLOWED, true)) {
            throw new WriteAttemptedFromIngestion($method);
        }

        return $this->api->get($account, $method, $parameters);
    }
}
