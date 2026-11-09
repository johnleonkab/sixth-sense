<?php

namespace App\Comms\Ingestion;

use App\Comms\Messages\Party;
use App\Connections\AccountNeedsReconnection;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsScope;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Reads Slack history for channels we already know: the allow list, and
 * channels stored from earlier messages. Listing every conversation is not
 * one of the methods this client may call.
 */
class SlackHistory
{
    public function __construct(private ReadOnlySlackClient $slack) {}

    /**
     * @return list<SlackInbound>
     */
    public function collect(ConnectedAccount $account, CarbonImmutable $since): array
    {
        $selfId = is_string($account->metadata['user_id'] ?? null) ? $account->metadata['user_id'] : '';
        $teamId = is_string($account->metadata['team_id'] ?? null)
            ? $account->metadata['team_id']
            : explode(':', (string) $account->external_account_id)[0];
        $items = [];

        foreach ($this->channels($account) as $channelId) {
            $kind = $this->kind($account, $channelId);
            $cursor = null;

            for ($page = 0; $page < 5; $page++) {
                $history = $this->slack->call($account, 'conversations.history', [
                    'channel' => $channelId,
                    'oldest' => (string) $since->getTimestamp(),
                    'limit' => 200,
                    'cursor' => $cursor,
                ]);

                foreach ($history['messages'] ?? [] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $mapped = $this->message($account, $teamId, $channelId, $kind, $selfId, $message);

                    if ($mapped !== null) {
                        $items[] = $mapped;
                    }
                }

                $cursor = $history['response_metadata']['next_cursor'] ?? null;

                if (! is_string($cursor) || $cursor === '') {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function channels(ConnectedAccount $account): array
    {
        $stored = CommsScope::query()->where('user_id', $account->user_id)->first();
        $allowed = $stored->slack_channel_ids ?? [];
        $seen = CommsMessage::query()
            ->where('user_id', $account->user_id)
            ->where('source', 'slack')
            ->whereNotNull('channel_ref')
            ->distinct()
            ->pluck('channel_ref')
            ->all();

        return array_values(array_unique(array_filter(
            [...$allowed, ...$seen],
            fn (mixed $id): bool => is_string($id) && $id !== '',
        )));
    }

    private function kind(ConnectedAccount $account, string $channelId): string
    {
        $info = $this->slack->call($account, 'conversations.info', ['channel' => $channelId]);
        $channel = is_array($info['channel'] ?? null) ? $info['channel'] : [];

        if (($channel['is_im'] ?? false) === true) {
            return 'dm';
        }

        if (($channel['is_mpim'] ?? false) === true) {
            return 'group';
        }

        return 'channel';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function message(
        ConnectedAccount $account,
        string $teamId,
        string $channelId,
        string $kind,
        string $selfId,
        array $message,
    ): ?SlackInbound {
        $subtype = isset($message['subtype']) ? (string) $message['subtype'] : null;
        $inner = is_array($message['message'] ?? null) ? $message['message'] : $message;
        $ts = (string) ($inner['ts'] ?? $message['ts'] ?? '');

        if ($ts === '') {
            return null;
        }

        $userId = (string) ($inner['user'] ?? $inner['bot_id'] ?? 'unknown');
        $profile = $this->profile($account, $userId);
        $text = (string) ($inner['text'] ?? '');
        $bot = $profile['bot'] || isset($inner['bot_id']) || $subtype === 'bot_message';

        return new SlackInbound(
            teamId: $teamId,
            channelId: $channelId,
            ts: $ts,
            channelKind: $kind,
            author: new Party($userId, $profile['email'], $profile['name'], $bot, $profile['handle']),
            recipients: [],
            text: $text,
            at: CarbonImmutable::createFromTimestamp((int) floor((float) $ts)),
            threadTs: isset($inner['thread_ts']) ? (string) $inner['thread_ts'] : null,
            mentionsUser: $selfId !== '' && str_contains($text, '<@'.$selfId.'>'),
            mine: $selfId !== '' && $userId === $selfId,
            subtype: $subtype,
            revision: $subtype === 'message_changed' ? (string) ($message['ts'] ?? '') : null,
        );
    }

    /**
     * @return array{email: ?string, name: string, bot: bool, handle: ?string}
     */
    private function profile(ConnectedAccount $account, string $userId): array
    {
        try {
            $response = $this->slack->call($account, 'users.info', ['user' => $userId]);
        } catch (AccountNeedsReconnection $exception) {
            throw $exception;
        } catch (RuntimeException) {
            return ['email' => null, 'name' => $userId, 'bot' => false, 'handle' => null];
        }

        $user = is_array($response['user'] ?? null) ? $response['user'] : [];
        $profile = is_array($user['profile'] ?? null) ? $user['profile'] : [];
        $email = filled($profile['email'] ?? null) ? strtolower((string) $profile['email']) : null;

        return [
            'email' => $email,
            'name' => (string) ($user['real_name'] ?? $user['name'] ?? $userId),
            'bot' => ($user['is_bot'] ?? false) === true,
            'handle' => isset($user['name']) ? (string) $user['name'] : null,
        ];
    }
}
