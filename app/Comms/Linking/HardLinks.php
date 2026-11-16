<?php

namespace App\Comms\Linking;

use App\Comms\Messages\Message;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsSegment;
use Illuminate\Support\Collection;

/**
 * Intent keys a segment points at directly: the same thread, a ticket, page,
 * pull request or URL already on a linked message, or an email it answers.
 * Membership in this list is the marker. It is not an automatic attach.
 */
class HardLinks
{
    /**
     * @return list<string>
     */
    public function for(CommsSegment $segment): array
    {
        $messages = CommsMessage::query()
            ->whereBelongsTo($segment->user)
            ->whereIn('id', $segment->message_ids ?? [])
            ->with('event')
            ->orderBy('id')
            ->get();
        $linked = CommsConversationLink::query()
            ->whereBelongsTo($segment->user)
            ->with('message.event')
            ->orderBy('intent_id')
            ->get()
            ->filter(fn (CommsConversationLink $link): bool => $link->message !== null)
            ->values();
        $corpus = trim($segment->restated."\n".implode("\n", $segment->unresolved_refs ?? [])."\n".$messages->map(fn (CommsMessage $message): string => $this->corpus($message))->implode("\n"));

        $keys = [
            ...$this->keys($this->byThread($messages, $linked)),
            ...$this->keys($this->byJira($corpus, $linked)),
            ...$this->keys($this->byNotion($corpus, $linked)),
            ...$this->keys($this->byPullRequest($corpus, $linked)),
            ...$this->keys($this->byUrl($corpus, $linked)),
            ...$this->keys($this->byHeaders($messages, $linked)),
        ];

        return array_values(array_unique($keys));
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     * @param  Collection<int, CommsConversationLink>  $linked
     * @return list<int>
     */
    private function byThread(Collection $messages, Collection $linked): array
    {
        $refs = $messages
            ->pluck('thread_ref')
            ->filter(fn (mixed $ref): bool => is_string($ref) && $ref !== '')
            ->all();

        return $this->intents($linked, function (CommsMessage $message) use ($refs): bool {
            return is_string($message->thread_ref) && in_array($message->thread_ref, $refs, true);
        });
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $linked
     * @return list<int>
     */
    private function byJira(string $corpus, Collection $linked): array
    {
        $keys = $this->jiraKeys($corpus);

        if ($keys === []) {
            return [];
        }

        return $this->intents($linked, fn (CommsMessage $message): bool => array_intersect($keys, $this->jiraKeys($this->corpus($message))) !== []);
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $linked
     * @return list<int>
     */
    private function byNotion(string $corpus, Collection $linked): array
    {
        $pages = $this->notionPages($corpus);

        if ($pages === []) {
            return [];
        }

        return $this->intents($linked, fn (CommsMessage $message): bool => array_intersect($pages, $this->notionPages($this->corpus($message))) !== []);
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $linked
     * @return list<int>
     */
    private function byPullRequest(string $corpus, Collection $linked): array
    {
        $numbers = $this->pullRequests($corpus);

        if ($numbers === []) {
            return [];
        }

        return $this->intents($linked, fn (CommsMessage $message): bool => array_intersect($numbers, $this->pullRequests($this->corpus($message))) !== []);
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $linked
     * @return list<int>
     */
    private function byUrl(string $corpus, Collection $linked): array
    {
        $urls = $this->urls($corpus);

        if ($urls === []) {
            return [];
        }

        return $this->intents($linked, fn (CommsMessage $message): bool => array_intersect($urls, $this->urls($this->corpus($message))) !== []);
    }

    /**
     * @param  Collection<int, CommsMessage>  $messages
     * @param  Collection<int, CommsConversationLink>  $linked
     * @return list<int>
     */
    private function byHeaders(Collection $messages, Collection $linked): array
    {
        $answered = [];

        foreach ($messages as $message) {
            foreach ([...$this->headerIds($message, 'in-reply-to'), ...$this->headerIds($message, 'references')] as $id) {
                $answered[$id] = true;
            }
        }

        if ($answered === []) {
            return [];
        }

        return $this->intents($linked, function (CommsMessage $message) use ($answered): bool {
            foreach ($this->identity($message) as $id) {
                if (isset($answered[$id])) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $linked
     * @return list<int>
     */
    private function intents(Collection $linked, callable $matches): array
    {
        $ids = [];

        foreach ($linked as $link) {
            if ($link->message instanceof CommsMessage && $matches($link->message)) {
                $ids[] = $link->intent_id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function keys(array $ids): array
    {
        return array_map(fn (int $id): string => 'intent:'.$id, $ids);
    }

    private function corpus(CommsMessage $message): string
    {
        return $message->text."\n".implode("\n", array_values($message->links ?? []));
    }

    /**
     * @return list<string>
     */
    private function jiraKeys(string $text): array
    {
        preg_match_all('/\b[A-Z][A-Z0-9]+-\d+\b/', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /**
     * @return list<string>
     */
    private function notionPages(string $text): array
    {
        preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $text, $dashed);
        preg_match_all('/(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])/i', $text, $plain);
        $pages = [];

        foreach ([...($dashed[0] ?? []), ...($plain[0] ?? [])] as $page) {
            $pages[] = strtolower(str_replace('-', '', $page));
        }

        return array_values(array_unique($pages));
    }

    /**
     * @return list<string>
     */
    private function pullRequests(string $text): array
    {
        preg_match_all('~github\.com/[^/\s]+/[^/\s]+/pull/(\d+)~i', $text, $github);
        preg_match_all('~(?<![A-Za-z0-9])pull/(\d+)~i', $text, $short);
        preg_match_all('~\bPR\s+#(\d+)~i', $text, $labeled);
        $numbers = [...($github[1] ?? []), ...($short[1] ?? []), ...($labeled[1] ?? [])];

        return array_values(array_unique($numbers));
    }

    /**
     * @return list<string>
     */
    private function urls(string $text): array
    {
        $urls = [];

        foreach (Message::linksIn($text) as $url) {
            $urls[] = rtrim($url, ".,);]>'\"?");
        }

        return array_values(array_unique(array_filter($urls, fn (string $url): bool => $url !== '')));
    }

    /**
     * @return list<string>
     */
    private function headerIds(CommsMessage $message, string $name): array
    {
        return $this->messageIds($this->header($message, $name));
    }

    /**
     * @return list<string>
     */
    private function identity(CommsMessage $message): array
    {
        return array_values(array_unique([
            ...$this->headerIds($message, 'message-id'),
            ...$this->messageIds($message->external_id),
        ]));
    }

    private function header(CommsMessage $message, string $name): mixed
    {
        $normalized = $message->event?->normalized;

        if (! is_array($normalized)) {
            return null;
        }

        $headers = is_array($normalized['headers'] ?? null) ? array_change_key_case($normalized['headers']) : [];

        return $headers[$name] ?? $normalized[str_replace('-', '_', $name)] ?? null;
    }

    /**
     * @return list<string>
     */
    private function messageIds(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        preg_match_all('/<[^>]+>|[^,\s]+/', $value, $matches);
        $ids = [];

        foreach ($matches[0] ?? [] as $id) {
            $id = strtolower(trim($id, "<> \t"));

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
