<?php

namespace App\Connectors\Chat;

/**
 * Someone in a chat workspace.
 */
final readonly class ChatPerson
{
    public function __construct(
        public string $ref,
        public string $account,
        public string $name,
        public ?string $handle = null,
        public ?string $title = null,
        public ?string $email = null,
        public ?string $avatarUrl = null,
        /** Messages exchanged lately: who and where the user talks to most. */
        public int $activity = 0,
    ) {}

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'name' => $this->name,
            'handle' => $this->handle,
            'title' => $this->title,
            'email' => $this->email,
            'avatar_url' => $this->avatarUrl,
        ];
    }
}
