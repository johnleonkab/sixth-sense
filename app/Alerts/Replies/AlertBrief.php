<?php

namespace App\Alerts\Replies;

use App\Alerts\AlertSource;
use Carbon\CarbonImmutable;

/**
 * Everything the reply engine gets to think about one alert.
 */
final readonly class AlertBrief
{
    /**
     * @param  string  $where  "Email", "Mensaje directo", "Mención en #producto"…
     * @param  list<string>  $participants  Who else is in the conversation (the people "reply all" reaches).
     * @param  list<array{from: string, when: string, text: string}>  $conversation  Messages around it, oldest first.
     */
    public function __construct(
        public AlertSource $source,
        public string $where,
        public string $from,
        public ?string $subject,
        public string $text,
        public CarbonImmutable $receivedAt,
        public array $participants = [],
        public array $conversation = [],
        public ?string $why = null,
        public string $findings = '',
    ) {}

    public function withFindings(string $findings): self
    {
        return new self(
            source: $this->source,
            where: $this->where,
            from: $this->from,
            subject: $this->subject,
            text: $this->text,
            receivedAt: $this->receivedAt,
            participants: $this->participants,
            conversation: $this->conversation,
            why: $this->why,
            findings: $findings,
        );
    }
}
