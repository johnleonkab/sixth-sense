<?php

namespace App\Ai;

/**
 * What a task asks a model, in the order that lets providers cache it: the
 * fixed instructions first, then what is stable for the person (their rules,
 * their context), then the conversation so far (only ever appended to), and
 * last the task itself, with everything that changes (the date, what is new).
 * Providers cache identical prefixes, so nothing dynamic goes before the end.
 */
final readonly class Prompt
{
    /**
     * @param  list<string>  $context  Stable for the person: rules, work context.
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history  Append-only.
     */
    public function __construct(
        public string $system,
        public string $task,
        public array $context = [],
        public array $history = [],
        public ?float $temperature = null,
    ) {}

    /**
     * The instructions and the stable context, as one block.
     */
    public function instructions(): string
    {
        return implode("\n\n", array_filter([trim($this->system), ...array_map('trim', $this->context)]));
    }
}
