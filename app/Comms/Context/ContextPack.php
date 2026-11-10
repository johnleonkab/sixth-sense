<?php

namespace App\Comms\Context;

use Carbon\CarbonImmutable;

/**
 * What one reader is allowed to see, already ordered, trimmed to its budget,
 * and with a ref on every item.
 */
final readonly class ContextPack
{
    /**
     * @param  list<array{id: int, ref: string, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, origin: string, at: CarbonImmutable}>  $facts
     * @param  list<array{ref: string, text: string}>  $intents
     * @param  list<array{ref: string, title: string, text: string}>  $documents
     * @param  list<array{refs: list<string>, summary: string}>  $findings
     */
    public function __construct(
        public Audience $audience,
        public array $facts = [],
        public array $intents = [],
        public array $documents = [],
        public array $findings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->facts === [] && $this->intents === [] && $this->documents === [] && $this->findings === [];
    }

    /**
     * @return list<int>
     */
    public function factIds(): array
    {
        return array_column($this->facts, 'id');
    }

    /**
     * @return list<array{id: int, ref: string, kind: string, subject: string, attribute: string, text: string, value: array<string, mixed>, origin: string, at: CarbonImmutable}>
     */
    public function where(string $attribute, ?string $kind = null): array
    {
        return array_values(array_filter(
            $this->facts,
            fn (array $fact): bool => $fact['attribute'] === $attribute && ($kind === null || $fact['kind'] === $kind),
        ));
    }

    public function summary(): string
    {
        return $this->where('summary')[0]['text'] ?? '';
    }

    /**
     * @return list<string>
     */
    public function rules(): array
    {
        return array_column(array_filter($this->where('rule'), fn (array $fact): bool => $fact['kind'] !== 'channel'), 'text');
    }
}
