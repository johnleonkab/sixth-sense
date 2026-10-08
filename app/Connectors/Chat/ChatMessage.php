<?php

namespace App\Connectors\Chat;

use Carbon\CarbonImmutable;

/**
 * A message, with mentions and links already turned into readable text.
 */
final readonly class ChatMessage
{
    /**
     * @param  list<ChatFile>  $files
     * @param  list<array{url: string, label: ?string}>  $links  Web links, whose URL the text may only show as a label.
     */
    public function __construct(
        public string $ref,
        public string $conversationRef,
        public string $conversationLabel,
        public string $authorName,
        public ?string $authorRef,
        public string $text,
        public CarbonImmutable $sentAt,
        public ?string $threadRef = null,
        public int $replyCount = 0,
        public array $files = [],
        public bool $mentionsMe = false,
        public bool $isMine = false,
        public ?string $url = null,
        public array $links = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toCanvas(string $timezone): array
    {
        return [
            'ref' => $this->ref,
            'conversation_ref' => $this->conversationRef,
            'conversation' => $this->conversationLabel,
            'author' => $this->authorName,
            'author_ref' => $this->authorRef,
            'text' => $this->text,
            'sent_at' => $this->sentAt->setTimezone($timezone)->toIso8601String(),
            'thread_ref' => $this->threadRef,
            'reply_count' => $this->replyCount,
            'files' => array_map(fn (ChatFile $file): array => $file->toArray(), $this->files),
            'mentions_me' => $this->mentionsMe,
            'is_mine' => $this->isMine,
            'url' => $this->url,
            'links' => $this->links,
        ];
    }

    /**
     * Compact description for the voice assistant.
     *
     * @return array<string, mixed>
     */
    public function toAssistant(string $timezone): array
    {
        return array_filter([
            'message_id' => $this->ref,
            'conversation' => $this->conversationLabel,
            'conversation_id' => $this->conversationRef,
            'from' => $this->isMine ? 'el usuario' : $this->authorName,
            'when' => $this->sentAt->setTimezone($timezone)->locale('es')->isoFormat('ddd D MMM, HH:mm'),
            'text' => mb_strimwidth($this->text, 0, 600, '…'),
            'thread_id' => $this->threadRef,
            'replies' => $this->replyCount ?: null,
            'files' => array_map(fn (ChatFile $file): array => ['file_id' => $file->ref, 'name' => $file->name], $this->files) ?: null,
            'links' => array_column($this->links, 'url') ?: null,
            'mentions_user' => $this->mentionsMe ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
