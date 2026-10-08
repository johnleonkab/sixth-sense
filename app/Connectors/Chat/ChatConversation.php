<?php

namespace App\Connectors\Chat;

/**
 * A channel, a private channel, a direct message or a group message.
 */
final readonly class ChatConversation
{
    public const KINDS = ['channel', 'private', 'dm', 'group'];

    /**
     * @param  'channel'|'private'|'dm'|'group'  $kind
     */
    public function __construct(
        public string $ref,
        public string $account,
        public string $kind,
        public string $name,
        public ?string $topic = null,
        public ?string $workspace = null,
        /** Messages exchanged lately: who and where the user talks to most. */
        public int $activity = 0,
    ) {}

    /**
     * "#producto", "Marta Ruiz", "Marta, Pablo".
     */
    public function label(): string
    {
        return in_array($this->kind, ['channel', 'private'], true) ? "#{$this->name}" : $this->name;
    }

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'kind' => $this->kind,
            'name' => $this->name,
            'label' => $this->label(),
            'topic' => $this->topic,
            'workspace' => $this->workspace,
        ];
    }
}
