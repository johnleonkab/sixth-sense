<?php

namespace App\Alerts\Questions;

use Carbon\CarbonImmutable;

/**
 * What the search found for a question someone asked the person: one source
 * that answers it, several to choose from, or nothing. Never an invented answer.
 */
final readonly class QuestionAnswer
{
    public const FOUND = 'found';

    public const SEVERAL = 'several';

    public const NONE = 'none';

    /** Older than this, the source may no longer be up to date. */
    public const STALE_DAYS = 14;

    /**
     * @param  list<array{kind: string, ref: string, title: string, url: ?string, updated_at: ?string, status?: ?string, key?: ?string}>  $sources  What it found, best first.
     * @param  string  $findings  For the reply engine: what the source says, and how to use it.
     */
    public function __construct(
        public string $outcome,
        public array $sources = [],
        public string $findings = '',
    ) {}

    /**
     * The warning when the one source found has not been touched in a while.
     */
    public function staleNote(?CarbonImmutable $now = null): ?string
    {
        $source = $this->outcome === self::FOUND ? ($this->sources[0] ?? null) : null;
        $updated = filled($source['updated_at'] ?? null) ? CarbonImmutable::parse($source['updated_at']) : null;

        if ($updated === null || $updated->greaterThan(($now ?? CarbonImmutable::now())->subDays(self::STALE_DAYS))) {
            return null;
        }

        $what = $source['kind'] === 'jira' ? 'El issue' : 'La fuente';

        return "{$what} no se toca desde el {$updated->setTimezone(config('agenda.timezone'))->format('d/m')}: puede que no esté al día.";
    }

    /**
     * As kept in the alert's proposal (proposal.research).
     *
     * @return array{outcome: string, sources: list<array<string, mixed>>, stale: ?string}
     */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'sources' => $this->sources, 'stale' => $this->staleNote()];
    }
}
