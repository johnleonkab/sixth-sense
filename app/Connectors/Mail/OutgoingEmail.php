<?php

namespace App\Connectors\Mail;

/**
 * An email ready to be sent from one of the user's accounts: a new one, a
 * reply in a thread, or a forward that carries the original's attachments.
 */
final readonly class OutgoingEmail
{
    public const MODES = ['new', 'reply', 'reply_all', 'forward'];

    /**
     * @param  'new'|'reply'|'reply_all'|'forward'  $mode
     * @param  list<MailAddress>  $to
     * @param  list<MailAddress>  $cc
     * @param  ?string  $original  Ref of the email replied to or forwarded.
     * @param  list<array{name: string, mime_type: ?string, size: int, id?: ?string}>  $attachments  Of the original, for forwards.
     */
    public function __construct(
        public string $mode,
        public int $accountId,
        public string $from,
        public array $to,
        public array $cc,
        public string $subject,
        public string $body,
        public ?string $original = null,
        public ?string $threadId = null,
        public ?string $inReplyTo = null,
        public ?string $references = null,
        public array $attachments = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $people = fn (array $addresses): array => array_map(fn (MailAddress $address): array => $address->toArray(), $addresses);

        return [
            'mode' => $this->mode,
            'account_id' => $this->accountId,
            'from' => $this->from,
            'to' => $people($this->to),
            'cc' => $people($this->cc),
            'subject' => $this->subject,
            'body' => $this->body,
            'original' => $this->original,
            'thread_id' => $this->threadId,
            'in_reply_to' => $this->inReplyTo,
            'references' => $this->references,
            'attachments' => $this->attachments,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $people = fn (array $addresses): array => array_map(fn (array $address): MailAddress => new MailAddress($address['name'], $address['email']), $addresses);

        return new self(
            mode: $data['mode'],
            accountId: (int) $data['account_id'],
            from: $data['from'],
            to: $people($data['to']),
            cc: $people($data['cc']),
            subject: $data['subject'],
            body: $data['body'],
            original: $data['original'] ?? null,
            threadId: $data['thread_id'] ?? null,
            inReplyTo: $data['in_reply_to'] ?? null,
            references: $data['references'] ?? null,
            attachments: $data['attachments'] ?? [],
        );
    }
}
