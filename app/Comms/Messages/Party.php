<?php

namespace App\Comms\Messages;

/**
 * One identity on a message: a Slack user, an email address, or the user.
 */
final readonly class Party
{
    public function __construct(
        public string $externalId,
        public ?string $email,
        public string $displayName,
        public bool $bot = false,
        public ?string $handle = null,
    ) {}

    /**
     * @return array{external_id: string, email: ?string, display_name: string, bot: bool, handle: ?string}
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'email' => $this->email,
            'display_name' => $this->displayName,
            'bot' => $this->bot,
            'handle' => $this->handle,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            externalId: (string) $data['external_id'],
            email: filled($data['email'] ?? null) ? (string) $data['email'] : null,
            displayName: (string) ($data['display_name'] ?? $data['external_id']),
            bot: (bool) ($data['bot'] ?? false),
            handle: filled($data['handle'] ?? null) ? (string) $data['handle'] : null,
        );
    }
}
