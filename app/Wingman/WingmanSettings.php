<?php

namespace App\Wingman;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * How the person wants Wingman: which calendars it follows (all, by
 * default), which meetings it never listens to (by words of their title),
 * and whether what was said is kept after the meeting (not by default).
 */
final readonly class WingmanSettings
{
    public const MAX_RULES = 30;

    /**
     * @param  list<string>|null  $calendars  Calendar accounts (emails) to follow; null for all.
     * @param  list<string>  $neverListen  Words of a title that keep Wingman from listening ("1:1", "RRHH").
     */
    public function __construct(
        public ?array $calendars = null,
        public array $neverListen = [],
        public bool $keepTranscript = false,
    ) {}

    public static function for(User $user): self
    {
        $data = $user->wingman_settings ?? [];

        return new self(
            calendars: isset($data['calendars']) && is_array($data['calendars']) ? array_values(array_filter($data['calendars'], 'is_string')) : null,
            neverListen: array_values(array_filter(array_map(fn (mixed $rule): string => is_string($rule) ? trim($rule) : '', $data['never_listen'] ?? []))),
            keepTranscript: (bool) ($data['keep_transcript'] ?? false),
        );
    }

    /**
     * @return array{calendars: list<string>|null, never_listen: list<string>, keep_transcript: bool}
     */
    public function toArray(): array
    {
        return ['calendars' => $this->calendars, 'never_listen' => $this->neverListen, 'keep_transcript' => $this->keepTranscript];
    }

    public function follows(?string $calendarAccount): bool
    {
        return $this->calendars === null || in_array(Str::lower((string) $calendarAccount), array_map(Str::lower(...), $this->calendars), true);
    }

    /**
     * The rule that keeps Wingman from listening to a meeting with this title, if any.
     */
    public function neverListensTo(string $title): ?string
    {
        $normalised = Str::lower(Str::ascii($title));

        foreach ($this->neverListen as $rule) {
            if ($rule !== '' && Str::contains($normalised, Str::lower(Str::ascii($rule)))) {
                return $rule;
            }
        }

        return null;
    }
}
