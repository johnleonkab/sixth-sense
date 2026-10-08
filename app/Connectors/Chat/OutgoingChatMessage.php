<?php

namespace App\Connectors\Chat;

/**
 * A message ready to be sent as the user.
 *
 * @phpstan-type Mention array{ref: string, name: string}
 */
final readonly class OutgoingChatMessage
{
    /**
     * @param  string  $to  A conversation ref, or a person ref for a direct message.
     * @param  list<array{ref: string, name: string}>  $mentions  People tagged in the text.
     */
    public function __construct(
        public string $to,
        public string $toLabel,
        public string $text,
        public array $mentions = [],
        public ?string $threadRef = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['to' => $this->to, 'to_label' => $this->toLabel, 'text' => $this->text, 'mentions' => $this->mentions, 'thread_ref' => $this->threadRef];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['to'], $data['to_label'], $data['text'], $data['mentions'] ?? [], $data['thread_ref'] ?? null);
    }
}
