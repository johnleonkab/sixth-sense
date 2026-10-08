<?php

namespace App\Connectors\Chat;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The Slack thread from docs/prototype.html, for tests and demo mode.
 */
class FakeChats implements Chats
{
    /** @var list<OutgoingChatMessage> */
    public array $sent = [];

    public function people(User $user, string $query, int $limit = 10): array
    {
        $query = ltrim($query, '@');

        return array_slice(self::ranked(array_map(
            fn (ChatPerson $person): array => [$person, $query === '' ? 1 : NameMatch::score($query, $person->name, $person->handle, Str::before((string) $person->email, '@'))],
            $this->directory(),
        )), 0, $limit);
    }

    public function conversations(User $user, string $query, ?string $kind = null, int $limit = 10): array
    {
        $query = ltrim($query, '#@');

        return array_slice(self::ranked(array_map(
            fn (ChatConversation $conversation): array => [$conversation, ($kind !== null && $conversation->kind !== $kind) ? 0 : ($query === '' ? 1 : NameMatch::score($query, $conversation->name))],
            $this->conversationList(),
        )), 0, $limit);
    }

    public function familiar(User $user, int $people = 25, int $conversations = 20): array
    {
        return [
            'people' => array_slice(array_values($this->directory()), 0, $people),
            'conversations' => array_slice(array_values(array_filter($this->conversationList(), fn (ChatConversation $conversation): bool => $conversation->kind !== 'dm')), 0, $conversations),
        ];
    }

    public function warm(User $user): void {}

    public function activity(User $user, CarbonImmutable $since, int $limit = 150): array
    {
        return array_slice(array_reverse($this->allMessages()), 0, $limit);
    }

    public function unread(User $user): array
    {
        $messages = collect($this->allMessages())->keyBy('ref');

        return [
            new UnreadMessage($messages['demo:D02:1004.000000'], 'dm', 2),
            new UnreadMessage($messages['demo:C01:1001.000400'], 'mention'),
        ];
    }

    public function view(User $user, string $conversationRef, ?string $aroundRef = null, ?string $threadRef = null, int $limit = 20): ?ChatView
    {
        $conversation = $this->conversationList()[$conversationRef] ?? null;

        if ($conversation === null) {
            return null;
        }

        // Like Slack: a message in a thread is shown in its thread.
        $threadRef ??= $aroundRef ? collect($this->allMessages())->firstWhere('ref', $aroundRef)?->threadRef : null;

        $messages = array_values(array_filter($this->allMessages(), fn (ChatMessage $message): bool => $message->conversationRef === $conversationRef
            && ($threadRef === null || $message->ref === $threadRef || $message->threadRef === $threadRef)
            && ($threadRef !== null || $message->threadRef === null || $message->threadRef === $message->ref)));

        return new ChatView($conversation, array_slice($messages, -$limit), $aroundRef, $threadRef);
    }

    public function search(User $user, ChatSearch $search): array
    {
        return array_slice(array_values(array_reverse(array_filter($this->allMessages(), fn (ChatMessage $message): bool => ($search->text === null || self::contains($message->text, $search->text))
            && ($search->from === null || self::contains($message->authorName, $search->from))
            && ($search->in === null || self::contains($message->conversationLabel, $search->in))))), 0, $search->limit);
    }

    public function file(User $user, string $fileRef): ?ChatFile
    {
        return $fileRef === 'demo:F01' ? new ChatFile('demo:F01', 'prepago-v4.png', 'image/png', (int) filesize(resource_path('demo/prepago-v4.png'))) : null;
    }

    public function download(User $user, string $fileRef): ?string
    {
        return $fileRef === 'demo:F01' ? (string) file_get_contents(resource_path('demo/prepago-v4.png')) : null;
    }

    /** @var list<string> Messages the user has answered, for tests. */
    public array $answered = [];

    public function repliedAfter(User $user, string $messageRef): bool
    {
        return in_array($messageRef, $this->answered, true);
    }

    public function canSend(User $user, string $to): bool
    {
        return str_starts_with($to, 'demo:');
    }

    public function send(User $user, OutgoingChatMessage $message): string
    {
        $this->sent[] = $message;

        return 'demo:sent:'.count($this->sent);
    }

    /**
     * @return array<string, ChatPerson>
     */
    private function directory(): array
    {
        return [
            'demo:U01' => new ChatPerson('demo:U01', 'demo@sixth-sense.test', 'Marta Ruiz', 'marta', 'Product Manager', 'marta@covermanager.test', activity: 14),
            'demo:U02' => new ChatPerson('demo:U02', 'demo@sixth-sense.test', 'Pablo Gil', 'pablo', 'Pagos', 'pablo@covermanager.test', activity: 9),
            'demo:U03' => new ChatPerson('demo:U03', 'demo@sixth-sense.test', 'Lucía Romero', 'lucia', 'Ingeniería', 'lucia@covermanager.test', activity: 2),
        ];
    }

    /**
     * @return array<string, ChatConversation>
     */
    private function conversationList(): array
    {
        return [
            'demo:C01' => new ChatConversation('demo:C01', 'demo@sixth-sense.test', 'channel', 'producto', 'Producto y diseño', 'CoverManager', 22),
            'demo:C02' => new ChatConversation('demo:C02', 'demo@sixth-sense.test', 'channel', 'pagos', null, 'CoverManager', 5),
            'demo:D01' => new ChatConversation('demo:D01', 'demo@sixth-sense.test', 'dm', 'Marta Ruiz', null, 'CoverManager', 14),
            'demo:D02' => new ChatConversation('demo:D02', 'demo@sixth-sense.test', 'dm', 'Pablo Gil', null, 'CoverManager', 9),
        ];
    }

    /**
     * @return list<ChatMessage>
     */
    private function allMessages(): array
    {
        $today = CarbonImmutable::today();
        $thread = 'demo:C01:1001.000100';
        $figma = new ChatFile('demo:F01', 'prepago-v4.png', 'image/png', 7191);
        $message = fn (string $ref, string $conversation, string $author, ?string $authorRef, string $text, string $time, array $extra = []): ChatMessage => new ChatMessage(...[
            'ref' => $ref,
            'conversationRef' => $conversation,
            'conversationLabel' => $this->conversationList()[$conversation]->label(),
            'authorName' => $author,
            'authorRef' => $authorRef,
            'text' => $text,
            'sentAt' => $today->setTimeFromTimeString($time),
            ...$extra,
        ]);

        return [
            $message('demo:C01:1000.000100', 'demo:C01', 'Lucía Romero', 'demo:U03', 'Migración de reservas cerrada en staging.', '08:30'),
            $message($thread, 'demo:C01', 'Marta Ruiz', 'demo:U01', 'He subido a Figma la versión nueva del flujo de prepago.', '08:47', ['threadRef' => $thread, 'replyCount' => 3, 'files' => [$figma]]),
            $message('demo:C01:1001.000200', 'demo:C01', 'Marta Ruiz', 'demo:U01', 'Cambia el paso 3: el cliente ve el importe total antes de elegir mesa.', '08:49', ['threadRef' => $thread]),
            $message('demo:C01:1001.000300', 'demo:C01', 'Pablo Gil', 'demo:U02', 'Desde Pagos, bien, siempre que el reembolso parcial siga en el paso 5.', '08:55', ['threadRef' => $thread]),
            $message('demo:C01:1001.000400', 'demo:C01', 'Marta Ruiz', 'demo:U01', '@John ¿puedes revisarlo antes de la 1:1 de las 12?', '08:58', ['threadRef' => $thread, 'mentionsMe' => true]),
            $message('demo:C02:1002.000100', 'demo:C02', 'Pablo Gil', 'demo:U02', 'Recordad: los reembolsos parciales pasan por riesgos.', '09:05'),
            $message('demo:D02:1003.000000', 'demo:D02', 'Pablo Gil', 'demo:U02', 'Buenos días John.', '09:10'),
            $message('demo:D02:1004.000000', 'demo:D02', 'Pablo Gil', 'demo:U02', '¿Tienes 5 minutos para lo del reembolso parcial?', '09:11'),
        ];
    }

    /**
     * Matches only, best first; among equals, the most active.
     *
     * @template T of ChatPerson|ChatConversation
     *
     * @param  array<array-key, array{0: T, 1: int}>  $scored
     * @return list<T>
     */
    private static function ranked(array $scored): array
    {
        $found = array_values(array_filter($scored, fn (array $entry): bool => $entry[1] > NameMatch::NONE));
        usort($found, fn (array $a, array $b): int => [$b[1], $b[0]->activity] <=> [$a[1], $a[0]->activity]);

        return array_column($found, 0);
    }

    private static function contains(?string $haystack, string $needle): bool
    {
        return Str::contains(Str::lower(Str::ascii((string) $haystack)), Str::lower(Str::ascii($needle)));
    }
}
