<?php

namespace App\Avisos;

use App\Alerts\AlertSource;
use Carbon\CarbonImmutable;

/**
 * One thing a connector noticed, before relevance decides if it is an aviso.
 */
final readonly class Candidate
{
    public function __construct(
        public AlertSource $source,
        public string $externalId,
        public string $actorName,
        public ?string $actorEmail,
        public string $title,
        public string $body,
        public ?string $url,
        public CarbonImmutable $receivedAt,
        public ?string $threadKey = null,
        public bool $directMessage = false,
        public bool $mention = false,
        public bool $importantSender = false,
        public bool $replyToOwnThread = false,
        public bool $bulk = false,
        public bool $fromSelf = false,
        public bool $ownedPage = false,
        public bool $assignedTask = false,
        public bool $inInbox = true,
        public ?string $accountEmail = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'external_id' => $this->externalId,
            'actor_name' => $this->actorName,
            'actor_email' => $this->actorEmail,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'received_at' => $this->receivedAt->toIso8601String(),
            'thread_key' => $this->threadKey,
            'direct_message' => $this->directMessage,
            'mention' => $this->mention,
            'important_sender' => $this->importantSender,
            'reply_to_own_thread' => $this->replyToOwnThread,
            'bulk' => $this->bulk,
            'from_self' => $this->fromSelf,
            'owned_page' => $this->ownedPage,
            'assigned_task' => $this->assignedTask,
            'in_inbox' => $this->inInbox,
            'account_email' => $this->accountEmail,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: AlertSource::from($data['source']),
            externalId: (string) $data['external_id'],
            actorName: (string) ($data['actor_name'] ?? 'Alguien'),
            actorEmail: $data['actor_email'] ?? null,
            title: (string) ($data['title'] ?? ''),
            body: (string) ($data['body'] ?? ''),
            url: $data['url'] ?? null,
            receivedAt: CarbonImmutable::parse($data['received_at']),
            threadKey: $data['thread_key'] ?? null,
            directMessage: (bool) ($data['direct_message'] ?? false),
            mention: (bool) ($data['mention'] ?? false),
            importantSender: (bool) ($data['important_sender'] ?? false),
            replyToOwnThread: (bool) ($data['reply_to_own_thread'] ?? false),
            bulk: (bool) ($data['bulk'] ?? false),
            fromSelf: (bool) ($data['from_self'] ?? false),
            ownedPage: (bool) ($data['owned_page'] ?? false),
            assignedTask: (bool) ($data['assigned_task'] ?? false),
            inInbox: (bool) ($data['in_inbox'] ?? true),
            accountEmail: $data['account_email'] ?? null,
        );
    }
}
