<?php

namespace App\Wingman\Closing;

use Illuminate\Support\Str;

/**
 * Something the person has to do after a call, as the planner says it,
 * before it is made ready: a message, a meeting, an issue, a task of theirs.
 */
final readonly class FollowUp
{
    public const KINDS = ['message', 'meeting', 'issue', 'task'];

    /**
     * @param  array<string, mixed>  $details  message: to, about, draft; meeting: title, duration_minutes, from_date, to_date; issue: summary, description, type; task: title, context, effort_minutes, deadline.
     */
    public function __construct(
        public string $kind,
        public string $title,
        public array $details,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! in_array($data['kind'] ?? null, self::KINDS, true) || blank($data['title'] ?? null)) {
            return null;
        }

        return new self($data['kind'], Str::limit(trim((string) $data['title']), 140), array_filter(
            array_diff_key($data, array_flip(['kind', 'title'])),
            fn (mixed $value): bool => filled($value),
        ));
    }
}
