<?php

namespace App\Connectors\Slack;

use App\Connections\AccountNeedsReconnection;
use App\Connections\Capability;
use App\Connectors\Chat\ChatConversation;
use App\Connectors\Chat\ChatFile;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\ChatSearch;
use App\Connectors\Chat\ChatView;
use App\Connectors\Chat\NameMatch;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Connectors\Chat\UnreadMessage;
use App\Models\ConnectedAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Slack, as the user, across every connected workspace.
 *
 * Refs: conversation "{account}:{channel}", message "{account}:{channel}:{ts}",
 * person "{account}:{user}", file "{account}:{file}".
 */
class SlackChats implements Chats
{
    /** How old, in seconds, each cache may be when read. */
    private const DIRECTORY_MAX_AGE = 12 * 3600;

    private const CONVERSATIONS_MAX_AGE = 3600;

    /** warm() refreshes what is older than this. */
    private const WARM_AFTER = 3600;

    /** A name that is not found refreshes the list, if it is older than this: it may be new. */
    private const MISSING_REFRESH_AFTER = 120;

    /** Caches are kept this long, to fall back on when Slack fails. */
    private const KEEP_FOR = 7 * 86400;

    private const MAX_PAGES = 5;

    /** Large workspaces list thousands of people, most of them deactivated. */
    private const DIRECTORY_MAX_PAGES = 30;

    /** How far back activity counts towards who and where the user talks most. */
    private const ACTIVITY_DAYS = 30;

    /** The user's own messages are sampled in this many periods, 100 per period, so the whole month counts. */
    private const ACTIVITY_PERIODS = 4;

    /** How far back mentions count as unread. */
    private const MENTIONS_DAYS = 3;

    public function __construct(private SlackApi $slack) {}

    public function people(User $user, string $query, int $limit = 10): array
    {
        $found = [];

        foreach ($user->accountsWith(Capability::SlackRead) as $account) {
            $found = [...$found, ...$this->matchingPeople($account, $query)];
        }

        return array_slice(self::ranked($found), 0, $limit);
    }

    public function conversations(User $user, string $query, ?string $kind = null, int $limit = 10): array
    {
        $found = [];

        foreach ($user->accountsWith(Capability::SlackRead) as $account) {
            $found = [...$found, ...$this->matchingConversations($account, $query, $kind)];
        }

        return array_slice(self::ranked($found), 0, $limit);
    }

    public function familiar(User $user, int $people = 25, int $conversations = 20): array
    {
        $familiarPeople = [];
        $familiarConversations = [];

        foreach ($user->accountsWith(Capability::SlackRead) as $account) {
            $activity = $this->stored("slack-activity:{$account->id}");
            $directory = $this->stored("slack-directory:{$account->id}");
            $listed = $this->stored("slack-conversations:{$account->id}");

            if ($activity === null || $directory === null || $listed === null) {
                continue;
            }

            foreach ($activity['people'] as $id => $count) {
                if (isset($directory[$id])) {
                    $familiarPeople[] = $this->person($account, $id, $directory[$id], $count);
                }
            }

            foreach ($listed as $channel) {
                $count = $activity['conversations'][$channel['id']] ?? 0;

                // Direct messages are already in the people.
                if ($count > 0 && ! ($channel['is_im'] ?? false)) {
                    $familiarConversations[] = $this->toConversation($account, $channel, $directory, $count);
                }
            }
        }

        $byActivity = fn (ChatPerson|ChatConversation $a, ChatPerson|ChatConversation $b): int => $b->activity <=> $a->activity;
        usort($familiarPeople, $byActivity);
        usort($familiarConversations, $byActivity);

        return [
            'people' => array_slice($familiarPeople, 0, $people),
            'conversations' => array_slice($familiarConversations, 0, $conversations),
        ];
    }

    public function warm(User $user): void
    {
        foreach ($user->accountsWith(Capability::SlackRead) as $account) {
            try {
                $this->directory($account, self::WARM_AFTER);
                $this->rawConversations($account, self::WARM_AFTER);

                if ($user->accountsWith(Capability::SlackSearch)->contains('id', $account->id)) {
                    $this->cached("slack-activity:{$account->id}", self::WARM_AFTER, fn (): array => $this->measureActivity($account));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function activity(User $user, CarbonImmutable $since, int $limit = 150): array
    {
        $found = [];

        foreach ($user->accountsWith(Capability::SlackSearch) as $account) {
            $me = $this->me($account);
            $after = $since->subDay()->toDateString();

            foreach (["from:<@{$me}> after:{$after}", "to:<@{$me}> after:{$after}", "<@{$me}> after:{$after}"] as $query) {
                try {
                    $matches = $this->slack->get($account, 'search.messages', ['query' => $query, 'sort' => 'timestamp', 'count' => 100])['messages']['matches'] ?? [];
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }

                foreach ($matches as $match) {
                    if ((float) $match['ts'] >= $since->getTimestamp()) {
                        $found["{$account->id}:{$match['channel']['id']}:{$match['ts']}"] ??= $this->fromSearch($account, $match);
                    }
                }
            }
        }

        $found = array_values($found);
        usort($found, fn (ChatMessage $a, ChatMessage $b): int => $b->sentAt <=> $a->sentAt);

        return array_slice($found, 0, $limit);
    }

    public function unread(User $user): array
    {
        $unread = [];

        foreach ($user->accountsWith(Capability::SlackRead) as $account) {
            try {
                $unread = [...$unread, ...$this->unreadDirectMessages($account), ...$this->unreadMentions($user, $account)];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        usort($unread, fn (UnreadMessage $a, UnreadMessage $b): int => $b->message->sentAt <=> $a->message->sentAt);

        return $unread;
    }

    public function view(User $user, string $conversationRef, ?string $aroundRef = null, ?string $threadRef = null, int $limit = 20): ?ChatView
    {
        [$account, $channel] = $this->resolve($user, $conversationRef, Capability::SlackRead);

        if ($account === null) {
            return null;
        }

        $conversation = $this->conversationList($account)[$channel] ?? $this->conversationInfo($account, $channel);

        if ($threadRef !== null) {
            $ts = self::tsOf($threadRef);
            $raw = $this->slack->get($account, 'conversations.replies', ['channel' => $channel, 'ts' => $ts, 'limit' => 100])['messages'] ?? [];

            return new ChatView($conversation, $this->messages($account, $conversation, $raw), focusRef: $aroundRef, threadRef: $threadRef);
        }

        if ($aroundRef !== null) {
            $ts = self::tsOf($aroundRef);

            // Replies are not in the channel history: if the message belongs to a thread, show the thread.
            $thread = $this->slack->get($account, 'conversations.replies', ['channel' => $channel, 'ts' => $ts, 'limit' => 100])['messages'] ?? [];

            if (count($thread) > 1) {
                $threadTs = $thread[0]['thread_ts'] ?? $thread[0]['ts'];

                return new ChatView($conversation, $this->messages($account, $conversation, $thread), focusRef: $aroundRef, threadRef: "{$account->id}:{$channel}:{$threadTs}");
            }

            $before = $this->slack->get($account, 'conversations.history', ['channel' => $channel, 'latest' => $ts, 'inclusive' => 'true', 'limit' => 8])['messages'] ?? [];
            $after = $this->slack->get($account, 'conversations.history', ['channel' => $channel, 'oldest' => $ts, 'limit' => 6])['messages'] ?? [];
            $raw = collect([...$before, ...$after])->unique('ts')->sortBy('ts')->values()->all();

            return new ChatView($conversation, $this->messages($account, $conversation, $raw), focusRef: $aroundRef);
        }

        $raw = array_reverse($this->slack->get($account, 'conversations.history', ['channel' => $channel, 'limit' => $limit])['messages'] ?? []);

        return new ChatView($conversation, $this->messages($account, $conversation, $raw));
    }

    /**
     * Messages of one thread, oldest as Slack returns them. The ref is the
     * thread parent, or a message that starts one.
     *
     * @return list<ChatMessage>
     */
    public function thread(User $user, string $ref): array
    {
        [$account, $channel] = $this->resolve($user, $ref, Capability::SlackRead);

        if ($account === null) {
            return [];
        }

        $conversation = $this->conversationList($account)[$channel] ?? $this->conversationInfo($account, $channel);
        $raw = $this->slack->get($account, 'conversations.replies', [
            'channel' => $channel,
            'ts' => self::tsOf($ref),
            'limit' => 200,
        ])['messages'] ?? [];

        return $this->messages($account, $conversation, $raw);
    }

    /**
     * Recent messages of a direct or group conversation, where replies are
     * just the next message.
     *
     * @return list<ChatMessage>
     */
    public function recent(User $user, string $conversationRef, CarbonImmutable $since): array
    {
        [$account, $channel] = $this->resolve($user, $conversationRef, Capability::SlackRead);

        if ($account === null) {
            return [];
        }

        $conversation = $this->conversationList($account)[$channel] ?? $this->conversationInfo($account, $channel);
        $raw = $this->slack->get($account, 'conversations.history', [
            'channel' => $channel,
            'oldest' => (string) $since->getTimestamp(),
            'limit' => 200,
        ])['messages'] ?? [];

        return $this->messages($account, $conversation, $raw);
    }

    public function search(User $user, ChatSearch $search): array
    {
        $found = [];

        foreach ($user->accountsWith(Capability::SlackSearch) as $account) {
            $query = $this->searchQuery($account, $search);

            if ($query === '') {
                continue;
            }

            try {
                $matches = $this->slack->get($account, 'search.messages', ['query' => $query, 'sort' => 'timestamp', 'count' => $search->limit])['messages']['matches'] ?? [];
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($matches as $match) {
                $conversation = $this->conversationList($account)[$match['channel']['id']] ?? $this->conversationFromSearch($account, $match['channel']);
                $found[] = $this->message($account, $conversation, $match);
            }
        }

        usort($found, fn (ChatMessage $a, ChatMessage $b): int => $b->sentAt <=> $a->sentAt);

        return array_slice($found, 0, $search->limit);
    }

    public function file(User $user, string $fileRef): ?ChatFile
    {
        [$account, $id] = $this->resolve($user, $fileRef, Capability::SlackFiles);

        if ($account === null) {
            return null;
        }

        $file = $this->slack->get($account, 'files.info', ['file' => $id])['file'] ?? null;

        return $file ? new ChatFile($fileRef, $file['name'] ?? $file['title'] ?? 'archivo', $file['mimetype'] ?? null, (int) ($file['size'] ?? 0)) : null;
    }

    public function download(User $user, string $fileRef): ?string
    {
        [$account, $id] = $this->resolve($user, $fileRef, Capability::SlackFiles);

        if ($account === null) {
            return null;
        }

        $file = $this->slack->get($account, 'files.info', ['file' => $id])['file'] ?? null;
        $url = $file['url_private_download'] ?? $file['url_private'] ?? null;

        return $url ? $this->slack->download($account, $url) : null;
    }

    public function repliedAfter(User $user, string $messageRef): bool
    {
        [$account, $channel] = $this->resolve($user, $messageRef, Capability::SlackRead);

        if ($account === null) {
            return false;
        }

        $ts = self::tsOf($messageRef);
        $mine = fn (array $message): bool => ($message['user'] ?? null) === $this->me($account) && (float) $message['ts'] > (float) $ts;

        // In its thread (whether it starts one or is a reply in one)…
        $thread = $this->slack->get($account, 'conversations.replies', ['channel' => $channel, 'ts' => $ts, 'limit' => 200])['messages'] ?? [];

        if (collect($thread)->contains($mine)) {
            return true;
        }

        // …or in the conversation itself.
        $after = $this->slack->get($account, 'conversations.history', ['channel' => $channel, 'oldest' => $ts, 'limit' => 100])['messages'] ?? [];

        return collect($after)->contains($mine);
    }

    public function canSend(User $user, string $to): bool
    {
        return $this->resolve($user, $to, Capability::SlackSend)[0] !== null;
    }

    public function send(User $user, OutgoingChatMessage $message): string
    {
        [$account, $id] = $this->resolve($user, $message->to, Capability::SlackSend);

        if ($account === null) {
            throw new RuntimeException('Esta cuenta de Slack no tiene permiso para enviar mensajes. Actívalo en Configuración y vuelve a conectarla.');
        }

        // A person: open (or reuse) the direct message with them.
        $channel = str_starts_with($id, 'U') || str_starts_with($id, 'W')
            ? $this->slack->post($account, 'conversations.open', ['users' => $id])['channel']['id']
            : $id;

        $text = SlackText::escape($message->text);

        foreach ($message->mentions as $mention) {
            $tag = '<@'.Str::after($mention['ref'], ':').'>';
            $pattern = '/@'.preg_quote(SlackText::escape($mention['name']), '/').'\b/u';
            $text = preg_match($pattern, $text) ? (string) preg_replace($pattern, $tag, $text, 1) : $tag.' '.$text;
        }

        $sent = $this->slack->post($account, 'chat.postMessage', [
            'channel' => $channel,
            'text' => $text,
            'thread_ts' => $message->threadRef ? self::tsOf($message->threadRef) : null,
        ]);

        return "{$account->id}:{$channel}:{$sent['ts']}";
    }

    /**
     * A message as search.messages returns it. Search results do not say
     * which thread a reply belongs to: their permalink does.
     *
     * @param  array<string, mixed>  $match
     */
    public function fromSearch(ConnectedAccount $account, array $match): ChatMessage
    {
        parse_str((string) parse_url((string) ($match['permalink'] ?? ''), PHP_URL_QUERY), $query);

        if (! isset($match['thread_ts']) && is_string($query['thread_ts'] ?? null)) {
            $match['thread_ts'] = $query['thread_ts'];
        }

        $conversation = $this->conversationList($account)[$match['channel']['id']] ?? $this->conversationFromSearch($account, $match['channel']);

        return $this->message($account, $conversation, $match);
    }

    /**
     * Someone in the account's workspace, by Slack user id.
     */
    public function personOf(ConnectedAccount $account, string $userId): ?ChatPerson
    {
        $person = $this->directory($account)[$userId] ?? null;

        return $person ? $this->person($account, $userId, $person) : null;
    }

    /**
     * The Slack user id of the account's owner.
     */
    public function me(ConnectedAccount $account): string
    {
        return (string) ($account->metadata['user_id'] ?? Str::after((string) $account->external_account_id, ':'));
    }

    /**
     * @return list<UnreadMessage>
     */
    private function unreadDirectMessages(ConnectedAccount $account): array
    {
        $unread = [];
        $listed = $this->slack->get($account, 'conversations.list', ['types' => 'im,mpim', 'exclude_archived' => 'true', 'limit' => 200])['channels'] ?? [];

        foreach ($listed as $channel) {
            $count = (int) ($channel['unread_count_display'] ?? 0);

            if ($count === 0) {
                continue;
            }

            $conversation = $this->conversationList($account)[$channel['id']] ?? $this->toConversation($account, $channel);
            $latest = $this->slack->get($account, 'conversations.history', ['channel' => $channel['id'], 'limit' => 1])['messages'][0] ?? null;

            if ($latest !== null && ($latest['user'] ?? null) !== $this->me($account)) {
                $unread[] = new UnreadMessage($this->message($account, $conversation, $latest), 'dm', $count);
            }

            if (count($unread) >= 10) {
                break;
            }
        }

        return $unread;
    }

    /**
     * Mentions of the user since they last read each channel.
     *
     * @return list<UnreadMessage>
     */
    private function unreadMentions(User $user, ConnectedAccount $account): array
    {
        if (! $user->accountsWith(Capability::SlackSearch)->contains('id', $account->id)) {
            return [];
        }

        $after = CarbonImmutable::now()->subDays(self::MENTIONS_DAYS)->toDateString();
        $matches = $this->slack->get($account, 'search.messages', [
            'query' => "<@{$this->me($account)}> after:{$after}",
            'sort' => 'timestamp',
            'count' => 20,
        ])['messages']['matches'] ?? [];

        $lastRead = [];
        $unread = [];

        foreach ($matches as $match) {
            $channel = $match['channel']['id'];

            if (($match['user'] ?? null) === $this->me($account) || ($match['channel']['is_im'] ?? false)) {
                continue;
            }

            $lastRead[$channel] ??= (string) ($this->slack->get($account, 'conversations.info', ['channel' => $channel])['channel']['last_read'] ?? '0');

            if ((float) $match['ts'] > (float) $lastRead[$channel]) {
                $conversation = $this->conversationList($account)[$channel] ?? $this->conversationFromSearch($account, $match['channel']);
                $unread[] = new UnreadMessage($this->message($account, $conversation, $match), 'mention');
            }
        }

        return $unread;
    }

    private function searchQuery(ConnectedAccount $account, ChatSearch $search): string
    {
        $parts = [$search->text];

        if ($search->from) {
            $person = self::ranked($this->matchingPeople($account, $search->from))[0] ?? null;
            $parts[] = $person ? 'from:<@'.Str::after($person->ref, ':').'>' : null;
        }

        if ($search->in) {
            $conversation = self::ranked($this->matchingConversations($account, $search->in))[0] ?? null;
            $parts[] = $conversation ? 'in:<#'.Str::after($conversation->ref, ':').'>' : null;
        }

        $parts[] = $search->after ? 'after:'.$search->after->subDay()->toDateString() : null;
        $parts[] = $search->before ? 'before:'.$search->before->addDay()->toDateString() : null;

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<ChatMessage>
     */
    private function messages(ConnectedAccount $account, ChatConversation $conversation, array $raw): array
    {
        return array_values(array_map(
            fn (array $message): ChatMessage => $this->message($account, $conversation, $message),
            array_filter($raw, fn (array $message): bool => ! in_array($message['subtype'] ?? null, ['channel_join', 'channel_leave'], true)),
        ));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function message(ConnectedAccount $account, ChatConversation $conversation, array $raw): ChatMessage
    {
        $directory = $this->directory($account);
        $authorId = $raw['user'] ?? null;
        $channel = Str::after($conversation->ref, ':');
        $ref = "{$account->id}:{$channel}:{$raw['ts']}";
        $threadTs = $raw['thread_ts'] ?? null;

        return new ChatMessage(
            ref: $ref,
            conversationRef: $conversation->ref,
            conversationLabel: $conversation->label(),
            authorName: $authorId ? ($directory[$authorId]['name'] ?? $raw['username'] ?? $authorId) : ($raw['username'] ?? $raw['bot_profile']['name'] ?? 'App'),
            authorRef: $authorId ? "{$account->id}:{$authorId}" : null,
            text: SlackText::readable((string) ($raw['text'] ?? ''), fn (string $id): ?string => $directory[$id]['name'] ?? null),
            sentAt: CarbonImmutable::createFromTimestamp((float) $raw['ts']),
            threadRef: $threadTs ? "{$account->id}:{$channel}:{$threadTs}" : null,
            replyCount: (int) ($raw['reply_count'] ?? 0),
            files: array_map(fn (array $file): ChatFile => new ChatFile("{$account->id}:{$file['id']}", $file['name'] ?? $file['title'] ?? 'archivo', $file['mimetype'] ?? null, (int) ($file['size'] ?? 0)), $raw['files'] ?? []),
            mentionsMe: str_contains((string) ($raw['text'] ?? ''), '<@'.$this->me($account).'>'),
            isMine: $authorId === $this->me($account),
            url: $raw['permalink'] ?? null,
            links: SlackText::links((string) ($raw['text'] ?? '')),
        );
    }

    /**
     * People of the account whose name, handle or email matches, with their score.
     *
     * @return list<array{0: ChatPerson, 1: int}>
     */
    private function matchingPeople(ConnectedAccount $account, string $query, bool $retried = false): array
    {
        $query = ltrim($query, '@');
        $activity = $this->measuredActivity($account)['people'];
        $found = [];

        foreach ($this->directory($account, $retried ? self::MISSING_REFRESH_AFTER : self::DIRECTORY_MAX_AGE) as $id => $person) {
            $score = $query === '' ? 1 : NameMatch::score($query, $person['name'], $person['handle'], Str::before((string) $person['email'], '@'));

            if ($score > NameMatch::NONE) {
                $found[] = [$this->person($account, $id, $person, $activity[$id] ?? 0), $score];
            }
        }

        // Someone who just joined is not in the cached directory yet.
        return $found === [] && ! $retried && $query !== '' ? $this->matchingPeople($account, $query, true) : $found;
    }

    /**
     * Conversations of the account whose name matches, with their score.
     *
     * @return list<array{0: ChatConversation, 1: int}>
     */
    private function matchingConversations(ConnectedAccount $account, string $query, ?string $kind = null, bool $retried = false): array
    {
        $query = ltrim($query, '#@');
        $found = [];

        foreach ($this->conversationList($account, $retried ? self::MISSING_REFRESH_AFTER : self::CONVERSATIONS_MAX_AGE) as $conversation) {
            if ($kind !== null && $conversation->kind !== $kind) {
                continue;
            }

            $score = $query === '' ? 1 : NameMatch::score($query, $conversation->name);

            if ($score > NameMatch::NONE) {
                $found[] = [$conversation, $score];
            }
        }

        // A channel created or joined a moment ago is not in the cached list yet.
        return $found === [] && ! $retried && $query !== '' ? $this->matchingConversations($account, $query, $kind, true) : $found;
    }

    /**
     * Best match first; among equals, the one the user talks to most, then the shortest name.
     *
     * @template T of ChatPerson|ChatConversation
     *
     * @param  list<array{0: T, 1: int}>  $found
     * @return list<T>
     */
    private static function ranked(array $found): array
    {
        usort($found, fn (array $a, array $b): int => [$b[1], $b[0]->activity, strlen($a[0]->name)] <=> [$a[1], $a[0]->activity, strlen($b[0]->name)]);

        return array_column($found, 0);
    }

    /**
     * How many messages the user exchanged lately with each person and in
     * each conversation: what they wrote, and where they were mentioned.
     *
     * @return array{people: array<string, int>, conversations: array<string, int>}
     */
    private function measureActivity(ConnectedAccount $account): array
    {
        $me = $this->me($account);
        $since = CarbonImmutable::today()->subDays(self::ACTIVITY_DAYS);
        $period = (int) ceil(self::ACTIVITY_DAYS / self::ACTIVITY_PERIODS);
        $listed = collect($this->rawConversations($account))->keyBy('id');
        $handles = collect($this->directory($account))->filter(fn (array $person): bool => filled($person['handle']))->pluck('id', 'handle');
        $people = [];
        $conversations = [];

        // Slack's "after" and "before" leave the day itself out.
        $queries = ["<@{$me}> after:{$since->subDay()->toDateString()}" => 2];

        for ($start = $since; $start->lte(CarbonImmutable::today()); $start = $start->addDays($period)) {
            $queries["from:<@{$me}> after:{$start->subDay()->toDateString()} before:{$start->addDays($period)->toDateString()}"] = 1;
        }

        foreach ($queries as $query => $pages) {
            for ($page = 1; $page <= $pages; $page++) {
                $response = $this->slack->get($account, 'search.messages', ['query' => $query, 'sort' => 'timestamp', 'count' => 100, 'page' => $page]);

                foreach ($response['messages']['matches'] ?? [] as $match) {
                    $channel = $listed[$match['channel']['id']] ?? $match['channel'];
                    $conversations[$channel['id']] = ($conversations[$channel['id']] ?? 0) + 1;

                    // The other side of a direct message, the members of a group, and whoever mentioned the user.
                    $others = [
                        $channel['user'] ?? null,
                        $match['user'] ?? null,
                        ...(($channel['is_mpim'] ?? false) ? array_map(fn (string $handle): ?string => $handles[$handle] ?? null, self::groupHandles((string) ($channel['name'] ?? ''))) : []),
                    ];

                    foreach (array_unique(array_filter($others)) as $person) {
                        if ($person !== $me) {
                            $people[$person] = ($people[$person] ?? 0) + 1;
                        }
                    }
                }

                if ($page >= (int) ($response['messages']['paging']['pages'] ?? 1)) {
                    break;
                }
            }
        }

        arsort($people);
        arsort($conversations);

        return ['people' => $people, 'conversations' => $conversations];
    }

    /**
     * Recent activity of the account, as last measured by warm().
     *
     * @return array{people: array<string, int>, conversations: array<string, int>}
     */
    private function measuredActivity(ConnectedAccount $account): array
    {
        return $this->stored("slack-activity:{$account->id}") ?? ['people' => [], 'conversations' => []];
    }

    /**
     * The workspace directory, cached: id => person.
     *
     * @return array<string, array{id: string, name: string, handle: ?string, title: ?string, email: ?string, avatar: ?string}>
     */
    private function directory(ConnectedAccount $account, int $maxAge = self::DIRECTORY_MAX_AGE): array
    {
        return $this->cached("slack-directory:{$account->id}", $maxAge, function () use ($account): array {
            $people = [];
            $cursor = null;

            for ($page = 0; $page < self::DIRECTORY_MAX_PAGES; $page++) {
                $response = $this->slack->get($account, 'users.list', ['limit' => 200, 'cursor' => $cursor]);

                foreach ($response['members'] ?? [] as $member) {
                    if (($member['deleted'] ?? false) || ($member['is_bot'] ?? false) || $member['id'] === 'USLACKBOT') {
                        continue;
                    }

                    $people[$member['id']] = [
                        'id' => $member['id'],
                        'name' => $member['profile']['real_name'] ?? $member['real_name'] ?? $member['name'],
                        'handle' => ($member['profile']['display_name'] ?? '') ?: ($member['name'] ?? null),
                        'title' => $member['profile']['title'] ?? null ?: null,
                        'email' => $member['profile']['email'] ?? null,
                        'avatar' => $member['profile']['image_72'] ?? null,
                    ];
                }

                $cursor = $response['response_metadata']['next_cursor'] ?? null;

                if (blank($cursor)) {
                    break;
                }
            }

            return $people;
        });
    }

    /**
     * The user's conversations: channel id => conversation.
     *
     * @return array<string, ChatConversation>
     */
    private function conversationList(ConnectedAccount $account, int $maxAge = self::CONVERSATIONS_MAX_AGE): array
    {
        $directory = $this->directory($account);
        $activity = $this->measuredActivity($account)['conversations'];
        $conversations = [];

        foreach ($this->rawConversations($account, $maxAge) as $channel) {
            $conversations[$channel['id']] = $this->toConversation($account, $channel, $directory, $activity[$channel['id']] ?? 0);
        }

        return $conversations;
    }

    /**
     * The user's conversations as Slack lists them, cached.
     *
     * @return list<array<string, mixed>>
     */
    private function rawConversations(ConnectedAccount $account, int $maxAge = self::CONVERSATIONS_MAX_AGE): array
    {
        return $this->cached("slack-conversations:{$account->id}", $maxAge, function () use ($account): array {
            $channels = [];
            $cursor = null;

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $response = $this->slack->get($account, 'users.conversations', [
                    'types' => 'public_channel,private_channel,mpim,im',
                    'exclude_archived' => 'true',
                    'limit' => 200,
                    'cursor' => $cursor,
                ]);

                $channels = [...$channels, ...($response['channels'] ?? [])];
                $cursor = $response['response_metadata']['next_cursor'] ?? null;

                if (blank($cursor)) {
                    break;
                }
            }

            return $channels;
        });
    }

    /**
     * The value cached under the key if it is younger than `$maxAge` seconds;
     * otherwise it is built again. If building fails, the old one is used.
     *
     * @template T
     *
     * @param  Closure(): T  $build
     * @return T
     */
    private function cached(string $key, int $maxAge, Closure $build): mixed
    {
        $entry = Cache::get($key);

        if (is_array($entry) && isset($entry['at']) && now()->getTimestamp() - $entry['at'] < $maxAge) {
            return $entry['value'];
        }

        try {
            $value = $build();
        } catch (AccountNeedsReconnection $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (! is_array($entry) || ! isset($entry['at'])) {
                throw $exception;
            }

            report($exception);

            return $entry['value'];
        }

        Cache::put($key, ['at' => now()->getTimestamp(), 'value' => $value], self::KEEP_FOR);

        return $value;
    }

    /**
     * What is cached under the key, however old, without asking Slack.
     */
    private function stored(string $key): mixed
    {
        $entry = Cache::get($key);

        return is_array($entry) && isset($entry['at']) ? $entry['value'] : null;
    }

    /**
     * @param  array<string, mixed>  $channel
     * @param  ?array<string, array{id: string, name: string, handle: ?string}>  $directory
     */
    private function toConversation(ConnectedAccount $account, array $channel, ?array $directory = null, int $activity = 0): ChatConversation
    {
        $directory ??= $this->directory($account);

        [$kind, $name] = match (true) {
            (bool) ($channel['is_im'] ?? false) => ['dm', $directory[$channel['user'] ?? '']['name'] ?? ($channel['user'] ?? 'Mensaje directo')],
            (bool) ($channel['is_mpim'] ?? false) => ['group', $this->groupName($directory, (string) ($channel['name'] ?? ''))],
            (bool) ($channel['is_private'] ?? false) => ['private', (string) $channel['name']],
            default => ['channel', (string) ($channel['name'] ?? $channel['id'])],
        };

        return new ChatConversation(
            ref: "{$account->id}:{$channel['id']}",
            account: (string) $account->email,
            kind: $kind,
            name: $name,
            topic: ($channel['topic']['value'] ?? null) ?: null,
            workspace: $account->metadata['team_name'] ?? null,
            activity: $activity,
        );
    }

    private function conversationInfo(ConnectedAccount $account, string $channel): ChatConversation
    {
        return $this->toConversation($account, $this->slack->get($account, 'conversations.info', ['channel' => $channel])['channel']);
    }

    /**
     * @param  array<string, mixed>  $channel  As search results give it.
     */
    private function conversationFromSearch(ConnectedAccount $account, array $channel): ChatConversation
    {
        return $this->toConversation($account, [
            'id' => $channel['id'],
            'name' => $channel['name'] ?? $channel['id'],
            'is_im' => $channel['is_im'] ?? false,
            'is_mpim' => $channel['is_mpim'] ?? false,
            'is_private' => $channel['is_private'] ?? false,
            'user' => $channel['user'] ?? null,
        ]);
    }

    /**
     * "mpdm-marta--pablo--jhon-1" → "Marta Ruiz, Pablo Gil".
     *
     * @param  array<string, array{id: string, name: string, handle: ?string}>  $directory
     */
    private function groupName(array $directory, string $name): string
    {
        $names = array_map(fn (string $handle): string => collect($directory)->firstWhere('handle', $handle)['name'] ?? $handle, self::groupHandles($name));

        return implode(', ', $names);
    }

    /**
     * "mpdm-marta--pablo--jhon-1" → ["marta", "pablo", "jhon"].
     *
     * @return list<string>
     */
    private static function groupHandles(string $name): array
    {
        return explode('--', (string) preg_replace('/^mpdm-|-\d+$/', '', $name));
    }

    /**
     * @param  array{id: string, name: string, handle: ?string, title: ?string, email: ?string, avatar: ?string}  $person
     */
    private function person(ConnectedAccount $account, string $id, array $person, int $activity = 0): ChatPerson
    {
        return new ChatPerson("{$account->id}:{$id}", (string) $account->email, $person['name'], $person['handle'], $person['title'], $person['email'], $person['avatar'], $activity);
    }

    /**
     * The account a ref belongs to (if it may use the capability) and the id within it.
     *
     * @return array{?ConnectedAccount, string}
     */
    private function resolve(User $user, string $ref, Capability $capability): array
    {
        [$accountId, $id] = array_pad(explode(':', $ref, 2), 2, '');
        $id = Str::before($id, ':');

        return [$user->accountsWith($capability)->firstWhere('id', (int) $accountId), $id];
    }

    private static function tsOf(string $messageRef): string
    {
        return Str::afterLast($messageRef, ':');
    }
}
