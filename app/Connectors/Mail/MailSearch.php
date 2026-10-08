<?php

namespace App\Connectors\Mail;

use Carbon\CarbonImmutable;

/**
 * What to look for. The provider narrows by sender, dates, words…; `pattern`
 * is then matched against each candidate's subject and full body.
 */
final readonly class MailSearch
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public ?string $subject = null,
        public ?string $text = null,
        public ?MailPattern $pattern = null,
        public ?CarbonImmutable $after = null,
        public ?CarbonImmutable $before = null,
        public bool $hasAttachment = false,
        public bool $unreadOnly = false,
        public string $folder = 'any',
        public int $limit = 10,
    ) {}

    /**
     * @return array<string, string>
     */
    public function describe(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
            'subject' => $this->subject,
            'text' => $this->text,
            'pattern' => $this->pattern?->source,
            'after' => $this->after?->toDateString(),
            'before' => $this->before?->toDateString(),
            'has_attachment' => $this->hasAttachment ? 'sí' : null,
            'unread_only' => $this->unreadOnly ? 'sí' : null,
            'folder' => $this->folder !== 'any' ? $this->folder : null,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }
}
