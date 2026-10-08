<?php

namespace App\Connectors\Google;

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\InboxWatcher;
use App\Connections\AccessTokens;
use App\Connectors\Mail\MailAddress;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Polls Gmail's history for messages added to the inbox since the last check.
 * Cheap: one request when nothing changed.
 *
 * @see https://developers.google.com/gmail/api/guides/sync#partial
 */
class GmailWatcher implements InboxWatcher
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const MAX_HISTORY_PAGES = 5;

    /** @var array<int, string> Next cursor per account, applied on commit(). */
    private array $pendingCursors = [];

    public function __construct(
        private AccessTokens $tokens,
        private GmailPayload $payload,
    ) {}

    public function source(): AlertSource
    {
        return AlertSource::Gmail;
    }

    public function newMessages(ConnectedAccount $account): array
    {
        $token = $this->tokens->for($account);

        if ($account->watch_cursor === null) {
            $this->pendingCursors[$account->id] = $this->currentHistoryId($token);

            return [];
        }

        [$ids, $cursor] = $this->addedSince($token, $account->watch_cursor);

        if ($cursor === null) {
            // Gmail forgot that point in history (it keeps about a week): start again from now.
            $this->pendingCursors[$account->id] = $this->currentHistoryId($token);

            return [];
        }

        $this->pendingCursors[$account->id] = $cursor;

        $ids = array_slice(array_values(array_unique($ids)), -config('alerts.max_new_messages_per_check'));

        return $this->fetch($account, $token, $ids);
    }

    public function commit(ConnectedAccount $account): void
    {
        if (isset($this->pendingCursors[$account->id])) {
            $account->update(['watch_cursor' => $this->pendingCursors[$account->id], 'watched_at' => now()]);
            unset($this->pendingCursors[$account->id]);
        }
    }

    private function currentHistoryId(string $token): string
    {
        $response = Http::withToken($token)->acceptJson()->timeout(10)->get(self::API.'/profile')->throw();

        return (string) $response->json('historyId');
    }

    /**
     * Ids of messages added to the inbox since the cursor, and the new cursor.
     * The cursor is null when Gmail no longer has that history.
     *
     * @return array{list<string>, ?string}
     */
    private function addedSince(string $token, string $cursor): array
    {
        $ids = [];
        $pageToken = null;
        $latest = $cursor;

        for ($page = 0; $page < self::MAX_HISTORY_PAGES; $page++) {
            $response = Http::withToken($token)->acceptJson()->timeout(10)->get(self::API.'/history', array_filter([
                'startHistoryId' => $cursor,
                'historyTypes' => 'messageAdded',
                'labelId' => 'INBOX',
                'maxResults' => 100,
                'pageToken' => $pageToken,
            ]));

            if ($response->status() === 404) {
                return [[], null];
            }

            $response->throw();
            $latest = (string) ($response->json('historyId') ?? $latest);

            foreach ($response->json('history', []) as $entry) {
                foreach ($entry['messagesAdded'] ?? [] as $added) {
                    $ids[] = $added['message']['id'];
                }
            }

            $pageToken = $response->json('nextPageToken');

            if ($pageToken === null) {
                break;
            }
        }

        return [$ids, $latest];
    }

    /**
     * @param  list<string>  $ids
     * @return list<InboxCandidate>
     */
    private function fetch(ConnectedAccount $account, string $token, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $id): mixed => $pool->as($id)->withToken($token)->acceptJson()->timeout(10)
                ->get(self::API.'/messages/'.rawurlencode($id), ['format' => 'full']),
            $ids,
        ));

        $candidates = [];

        foreach ($responses as $id => $response) {
            if (! $response instanceof Response) {
                throw new RuntimeException("Gmail message [{$id}] could not be fetched.");
            }

            // Deleted between the history and now: nothing to alert about.
            if ($response->status() === 404) {
                continue;
            }

            $candidates[] = $this->toCandidate($response->throw()->json(), $account);
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toCandidate(array $data, ConnectedAccount $account): InboxCandidate
    {
        $headers = $this->payload->headers($data['payload'] ?? []);
        $precedence = strtolower($headers['precedence'] ?? '');

        return new InboxCandidate(
            source: AlertSource::Gmail,
            externalId: $account->id.'.'.$data['id'],
            account: (string) $account->email,
            from: MailAddress::parse($headers['from'] ?? null),
            to: MailAddress::parseList($headers['to'] ?? null),
            cc: MailAddress::parseList($headers['cc'] ?? null),
            subject: $headers['subject'] ?? '(Sin asunto)',
            excerpt: $this->payload->body($data['payload'] ?? []) ?: html_entity_decode($data['snippet'] ?? '', ENT_QUOTES | ENT_HTML5),
            receivedAt: CarbonImmutable::createFromTimestampMs((int) ($data['internalDate'] ?? 0)),
            labels: $data['labelIds'] ?? [],
            isReply: isset($headers['in-reply-to']),
            isBulk: isset($headers['list-unsubscribe']) || isset($headers['list-id']) || in_array($precedence, ['bulk', 'list', 'junk'], true),
            url: 'https://mail.google.com/mail/?authuser='.urlencode((string) $account->email).'#all/'.$data['id'],
            threadKey: isset($data['threadId']) ? $account->id.'.'.$data['threadId'] : null,
        );
    }
}
