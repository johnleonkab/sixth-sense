<?php

namespace App\Connectors\Slack;

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\InboxWatcher;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Mail\MailAddress;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Polls Slack for direct messages, group messages and mentions of the user
 * since the last check: two searches a minute.
 *
 * Slack's search index lags a little behind, so each check looks back a few
 * minutes and remembers what it already handled.
 *
 * @see https://docs.slack.dev/reference/methods/search.messages
 */
class SlackWatcher implements InboxWatcher
{
    /** How far behind the cursor to look again, for messages indexed late. */
    private const LAG_SECONDS = 600;

    /** @var array<int, string> Next cursor per account, applied on commit(). */
    private array $pendingCursors = [];

    /** @var array<int, list<string>> Messages handled per account, remembered on commit(). */
    private array $pendingSeen = [];

    public function __construct(
        private SlackApi $slack,
        private SlackChats $chats,
    ) {}

    public function source(): AlertSource
    {
        return AlertSource::Slack;
    }

    public function newMessages(ConnectedAccount $account): array
    {
        if ($account->watch_cursor === null) {
            $this->pendingCursors[$account->id] = CarbonImmutable::now()->format('U.u');

            return [];
        }

        $me = $this->chats->me($account);
        $cursor = (float) $account->watch_cursor;
        $since = $cursor - self::LAG_SECONDS;
        $seen = Cache::get($this->seenKey($account), []);
        $after = CarbonImmutable::createFromTimestamp($since)->subDay()->toDateString();
        $queries = ["to:<@{$me}> after:{$after}", "<@{$me}> after:{$after}"];
        $ownedChannel = [];
        $signals = $account->user->slackAttentionProfile?->signals;

        foreach (array_slice(is_array($signals) ? ($signals['owned_channels'] ?? []) : [], 0, 5) as $channel) {
            if (is_string($channel) && str_starts_with($channel, '#')) {
                $query = "in:{$channel} after:{$after}";
                $queries[] = $query;
                $ownedChannel[$query] = true;
            }
        }

        $matches = [];

        // To the user (direct and group messages), mentioning them anywhere,
        // and questions in channels the attention profile says they own.
        foreach ($queries as $query) {
            $found = $this->slack->get($account, 'search.messages', ['query' => $query, 'sort' => 'timestamp', 'sort_dir' => 'desc', 'count' => 50]);

            foreach ($found['messages']['matches'] ?? [] as $match) {
                $key = "{$match['channel']['id']}:{$match['ts']}";

                if (isset($matches[$key])) {
                    continue;
                }

                $match['_owned_channel'] = isset($ownedChannel[$query]);
                $matches[$key] = $match;
            }
        }

        $latest = $cursor;
        $candidates = [];
        $handled = [];

        foreach ($matches as $key => $match) {
            $ts = (float) $match['ts'];
            $latest = max($latest, $ts);

            if ($ts <= $since || in_array($key, $seen, true)) {
                continue;
            }

            $handled[] = $key;

            // The user's own messages, and apps', never need an answer.
            if (($match['user'] ?? null) === $me || blank($match['user'] ?? null) || isset($match['bot_id']) || ($match['subtype'] ?? null) === 'bot_message') {
                continue;
            }

            $candidates[] = $this->toCandidate($account, $this->chats->fromSearch($account, $match), $match);
        }

        $this->pendingCursors[$account->id] = sprintf('%.6F', $latest);
        $this->pendingSeen[$account->id] = array_slice([...$seen, ...$handled], -500);

        usort($candidates, fn (InboxCandidate $a, InboxCandidate $b): int => $a->receivedAt <=> $b->receivedAt);

        return array_slice($candidates, -config('alerts.max_new_messages_per_check'));
    }

    public function commit(ConnectedAccount $account): void
    {
        if (isset($this->pendingSeen[$account->id])) {
            Cache::put($this->seenKey($account), $this->pendingSeen[$account->id], now()->addDay());
            unset($this->pendingSeen[$account->id]);
        }

        if (isset($this->pendingCursors[$account->id])) {
            $account->update(['watch_cursor' => $this->pendingCursors[$account->id], 'watched_at' => now()]);
            unset($this->pendingCursors[$account->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function toCandidate(ConnectedAccount $account, ChatMessage $message, array $match): InboxCandidate
    {
        $channel = $match['channel'];
        $kind = match (true) {
            (bool) ($channel['is_im'] ?? false) => 'dm',
            (bool) ($channel['is_mpim'] ?? false) => 'group',
            str_contains((string) ($match['text'] ?? ''), '<@'.$this->chats->me($account).'>') => 'mention',
            (bool) ($match['_owned_channel'] ?? false) => 'channel',
            default => 'mention',
        };

        $author = $message->authorRef ? $this->chats->personOf($account, Str::after($message->authorRef, ':')) : null;
        $channelRef = $message->conversationRef;

        return new InboxCandidate(
            source: AlertSource::Slack,
            externalId: $message->ref,
            account: (string) $account->email,
            from: new MailAddress($message->authorName, $author?->email),
            to: [],
            cc: [],
            subject: match ($kind) {
                'dm' => 'Mensaje directo',
                'group' => "Grupo con {$message->conversationLabel}",
                'channel' => 'Canal '.$message->conversationLabel,
                default => "Mención en {$message->conversationLabel}",
            },
            excerpt: $message->text,
            receivedAt: $message->sentAt,
            labels: [$kind],
            isReply: $message->threadRef !== null && $message->threadRef !== $message->ref,
            url: $message->url,
            // A thread is one conversation; so is a direct message without threads.
            threadKey: $message->threadRef ?? (in_array($kind, ['mention', 'channel'], true) ? $message->ref : $channelRef),
        );
    }

    private function seenKey(ConnectedAccount $account): string
    {
        return "slack-alerts-seen:{$account->id}";
    }
}
