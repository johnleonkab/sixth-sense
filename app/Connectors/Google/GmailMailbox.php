<?php

namespace App\Connectors\Google;

use App\Connections\AccessTokens;
use App\Connections\Capability;
use App\Connectors\Mail\FoundMessage;
use App\Connectors\Mail\MailAddress;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Mail\MailSearchResult;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Searches Gmail in every account allowed to read email. Gmail narrows the
 * candidates with its own query; the pattern is then matched on the full text.
 *
 * @see https://developers.google.com/gmail/api/reference/rest/v1/users.messages
 */
class GmailMailbox implements Mailbox
{
    private const MESSAGES_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/messages';

    private const THREADS_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/threads';

    /** Emails whose full body is checked against a pattern, per account. */
    private const PATTERN_CANDIDATES = 40;

    private const METADATA_HEADERS = ['From', 'To', 'Cc', 'Subject', 'Date', 'Message-ID', 'References', 'Reply-To'];

    public function __construct(
        private AccessTokens $tokens,
        private GmailPayload $payload,
    ) {}

    public function search(User $user, MailSearch $search): MailSearchResult
    {
        $failed = [];
        $accounts = [];

        foreach ($user->accountsWith(Capability::GmailRead) as $account) {
            try {
                $accounts[] = ['account' => $account, 'token' => $this->tokens->for($account)];
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $account->email;
            }
        }

        $candidatesPerAccount = $search->pattern ? self::PATTERN_CANDIDATES : $search->limit;
        $query = $this->query($search);

        $lists = $accounts === [] ? [] : Http::pool(fn (Pool $pool): array => array_map(
            fn (int $index): mixed => $pool->as((string) $index)
                ->withToken($accounts[$index]['token'])
                ->acceptJson()
                ->timeout(10)
                ->get(self::MESSAGES_URL, ['q' => $query, 'maxResults' => $candidatesPerAccount]),
            array_keys($accounts),
        ));

        $wanted = [];
        $mayHaveMore = false;

        foreach ($lists as $index => $response) {
            $email = $accounts[(int) $index]['account']->email;

            if (! $response instanceof Response || $response->failed()) {
                report(new RuntimeException("Gmail search failed for [{$email}]."));
                $failed[] = $email;

                continue;
            }

            $mayHaveMore = $mayHaveMore || filled($response->json('nextPageToken'));

            foreach ($response->json('messages', []) as $item) {
                $wanted[] = ['index' => (int) $index, 'id' => $item['id']];
            }
        }

        $messages = $this->fetch($accounts, $wanted, full: $search->pattern !== null);

        $found = [];

        foreach ($messages as $message) {
            if ($search->pattern === null) {
                $found[] = new FoundMessage($message);

                continue;
            }

            $excerpt = $search->pattern->excerptFor($message);

            if ($excerpt !== null) {
                $found[] = new FoundMessage($message, $excerpt);
            }
        }

        usort($found, fn (FoundMessage $a, FoundMessage $b): int => $b->message->date <=> $a->message->date);

        return new MailSearchResult(
            messages: array_slice($found, 0, $search->limit),
            accounts: array_values(array_diff(array_map(fn (array $item): string => $item['account']->email, $accounts), $failed)),
            failedAccounts: $failed,
            scanned: $search->pattern ? count($messages) : 0,
            mayHaveMore: $mayHaveMore || count($found) > $search->limit,
        );
    }

    public function message(User $user, string $ref): ?MailMessage
    {
        [$accountId, $messageId] = array_pad(explode('.', $ref, 2), 2, null);

        $account = $user->accountsWith(Capability::GmailRead)->firstWhere('id', (int) $accountId);

        if ($account === null || blank($messageId)) {
            return null;
        }

        $response = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(10)
            ->get(self::MESSAGES_URL.'/'.rawurlencode($messageId), ['format' => 'full']);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException("Gmail message [{$ref}] could not be read: HTTP {$response->status()}.");
        }

        return $this->toMessage($response->json(), $account, full: true);
    }

    public function attachment(User $user, string $ref, int $index): ?string
    {
        [$accountId, $messageId] = array_pad(explode('.', $ref, 2), 2, null);
        $account = $user->accountsWith(Capability::GmailRead)->firstWhere('id', (int) $accountId);

        if ($account === null || blank($messageId)) {
            return null;
        }

        $request = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(30);

        // Attachment ids change on every read of the message: take a fresh one.
        $data = $request->get(self::MESSAGES_URL.'/'.rawurlencode($messageId), ['format' => 'full'])->json();
        $part = $this->payload->attachmentParts($data['payload'] ?? [])[$index] ?? null;

        if ($part === null) {
            return null;
        }

        $encoded = isset($part['body']['attachmentId'])
            ? $request->get(self::MESSAGES_URL.'/'.rawurlencode($messageId).'/attachments/'.rawurlencode($part['body']['attachmentId']))->json('data')
            : ($part['body']['data'] ?? null);

        return is_string($encoded) ? (string) base64_decode(strtr($encoded, '-_', '+/')) : null;
    }

    public function repliedAfter(User $user, string $ref): bool
    {
        [$accountId, $messageId] = array_pad(explode('.', $ref, 2), 2, null);
        $account = $user->accountsWith(Capability::GmailRead)->firstWhere('id', (int) $accountId);

        if ($account === null || blank($messageId)) {
            return false;
        }

        $request = Http::withToken($this->tokens->for($account))->acceptJson()->timeout(10);
        $message = $request->get(self::MESSAGES_URL.'/'.rawurlencode($messageId), ['format' => 'minimal']);

        if ($message->failed() || blank($message->json('threadId'))) {
            return false;
        }

        $thread = $request->get(self::THREADS_URL.'/'.rawurlencode($message->json('threadId')), ['format' => 'minimal'])->throw();
        $receivedAt = (int) $message->json('internalDate');

        return collect($thread->json('messages', []))->contains(fn (array $sent): bool => in_array('SENT', $sent['labelIds'] ?? [], true)
            && (int) ($sent['internalDate'] ?? 0) > $receivedAt);
    }

    /**
     * Gmail search syntax for the structured parts of the search.
     *
     * @see https://support.google.com/mail/answer/7190
     */
    private function query(MailSearch $search): string
    {
        $quote = fn (string $value): string => '"'.str_replace('"', '', $value).'"';

        return implode(' ', array_filter([
            $search->from ? 'from:'.$quote($search->from) : null,
            $search->to ? 'to:'.$quote($search->to) : null,
            $search->subject ? 'subject:('.$search->subject.')' : null,
            $search->text,
            $search->after ? 'after:'.$search->after->format('Y/m/d') : null,
            $search->before ? 'before:'.$search->before->addDay()->format('Y/m/d') : null,
            $search->hasAttachment ? 'has:attachment' : null,
            $search->unreadOnly ? 'is:unread' : null,
            match ($search->folder) {
                'inbox' => 'in:inbox',
                'primary' => 'in:inbox category:primary',
                'sent' => 'in:sent',
                default => null,
            },
        ]));
    }

    /**
     * Fetch the messages in parallel: metadata for listings, full content to match a pattern.
     *
     * @param  list<array{account: ConnectedAccount, token: string}>  $accounts
     * @param  list<array{index: int, id: string}>  $wanted
     * @return list<MailMessage>
     */
    private function fetch(array $accounts, array $wanted, bool $full): array
    {
        if ($wanted === []) {
            return [];
        }

        $query = $full
            ? 'format=full'
            : 'format=metadata&'.implode('&', array_map(fn (string $header): string => "metadataHeaders={$header}", self::METADATA_HEADERS));

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (int $position): mixed => $pool->as((string) $position)
                ->withToken($accounts[$wanted[$position]['index']]['token'])
                ->acceptJson()
                ->timeout(10)
                ->get(self::MESSAGES_URL.'/'.rawurlencode($wanted[$position]['id']).'?'.$query),
            array_keys($wanted),
        ));

        $messages = [];

        foreach ($responses as $position => $response) {
            if ($response instanceof Response && $response->successful()) {
                $messages[] = $this->toMessage($response->json(), $accounts[$wanted[(int) $position]['index']]['account'], $full);
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toMessage(array $data, ConnectedAccount $account, bool $full): MailMessage
    {
        $headers = collect($this->payload->headers($data['payload'] ?? []));

        $timezone = config('app.timezone');

        return new MailMessage(
            ref: $account->id.'.'.$data['id'],
            account: $account->email,
            from: MailAddress::parse($headers->get('from')),
            to: MailAddress::parseList($headers->get('to')),
            subject: $headers->get('subject') ?: '(Sin asunto)',
            date: CarbonImmutable::createFromTimestampMs((int) ($data['internalDate'] ?? 0), $timezone),
            snippet: html_entity_decode($data['snippet'] ?? '', ENT_QUOTES | ENT_HTML5),
            unread: in_array('UNREAD', $data['labelIds'] ?? [], true),
            body: $full ? $this->payload->body($data['payload'] ?? []) : null,
            cc: MailAddress::parseList($headers->get('cc')),
            attachments: $full ? $this->payload->attachments($data['payload'] ?? []) : [],
            url: 'https://mail.google.com/mail/?authuser='.urlencode((string) $account->email).'#all/'.$data['id'],
            threadId: $data['threadId'] ?? null,
            messageId: $headers->get('message-id'),
            references: $headers->get('references'),
            replyTo: MailAddress::parseList($headers->get('reply-to')),
        );
    }
}
