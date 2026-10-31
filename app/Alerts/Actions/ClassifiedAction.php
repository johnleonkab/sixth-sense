<?php

namespace App\Alerts\Actions;

/**
 * One kind of action a message asks of the person, and how sure the system is:
 * a call, a task, a question to answer, or just a reply. When unsure, it says
 * what is not clear and prepares nothing for it.
 */
final readonly class ClassifiedAction
{
    public const CALL = 'call';

    public const TASK = 'task';

    public const QUESTION = 'question';

    public const REPLY = 'reply';

    public const CLASSES = [self::CALL, self::TASK, self::QUESTION, self::REPLY];

    public const SURE = 'sure';

    public const DOUBT = 'doubt';

    /**
     * @param  array<string, mixed>  $data  What the flow of that class needs: people and length for a call,
     *                                      the deliverable and deadline for a task, what to look up for a question.
     */
    public function __construct(
        public string $class,
        public string $confidence,
        public string $summary,
        public ?string $doubt = null,
        public array $data = [],
    ) {}

    public function sure(): bool
    {
        return $this->confidence === self::SURE;
    }

    /**
     * As kept in the alert's proposal (proposal.actions), under a key the screen and the voice use.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $key): array
    {
        return array_filter([
            'key' => $key,
            'class' => $this->class,
            'confidence' => $this->confidence,
            'summary' => $this->summary,
            'doubt' => $this->doubt,
            'data' => $this->data === [] ? null : $this->data,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public static function fromArray(array $action): self
    {
        return new self(
            class: (string) $action['class'],
            confidence: (string) ($action['confidence'] ?? self::DOUBT),
            summary: (string) ($action['summary'] ?? ''),
            doubt: $action['doubt'] ?? null,
            data: $action['data'] ?? [],
        );
    }
}
