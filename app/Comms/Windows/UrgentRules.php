<?php

namespace App\Comms\Windows;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Intents\PersonRef;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsUrgentRule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Whether a message skips the settle wait. Rules are rows projected from
 * user.configured events. A user who has never set one starts with the
 * default: a direct message from someone whose weight is at least 35.
 */
class UrgentRules
{
    public function __construct(private EventLog $log, private PersonRef $people) {}

    /**
     * Every rule the user has, including inactive ones. A user with none
     * yet receives the default before the list is returned.
     *
     * @return Collection<int, CommsUrgentRule>
     */
    public function all(User $user): Collection
    {
        $this->seed($user);

        return CommsUrgentRule::query()->whereBelongsTo($user)->orderBy('id')->get();
    }

    /**
     * How many messages of the last 30 days contain the keyword, plus three
     * recent examples, using the same whole-word match as a saved rule.
     *
     * @return array{count: int, examples: list<array{id: int, text: string, at: string}>}
     */
    public function keywordPreview(User $user, string $keyword): array
    {
        $matched = CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->whereNull('deleted_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (CommsMessage $message): bool => $this->keyword($message->text, $keyword))
            ->values();

        return [
            'count' => $matched->count(),
            'examples' => $matched->take(3)->map(fn (CommsMessage $message): array => [
                'id' => $message->id,
                'text' => $message->text,
                'at' => $message->occurred_at->toIso8601String(),
            ])->all(),
        ];
    }

    public function matches(User $user, CommsMessage $message): bool
    {
        $this->seed($user);

        $message->loadMissing('author');

        foreach (CommsUrgentRule::query()->whereBelongsTo($user)->where('active', true)->orderBy('id')->get() as $rule) {
            if ($this->ruleMatches($user, $message, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function apply(User $user, array $data): void
    {
        $rule = is_array($data['rule'] ?? null) ? $data['rule'] : [];
        $kind = (string) ($rule['kind'] ?? '');
        $ref = (string) ($rule['ref'] ?? '');

        if ($kind === '' || $ref === '') {
            return;
        }

        $where = ['user_id' => $user->id, 'kind' => $kind, 'ref' => $ref];

        if (($data['op'] ?? 'set') === 'remove') {
            CommsUrgentRule::query()->where($where)->delete();

            return;
        }

        CommsUrgentRule::query()->updateOrCreate($where, [
            'sources' => array_values(is_array($rule['sources'] ?? null) ? $rule['sources'] : []),
            'active' => (bool) ($rule['active'] ?? true),
        ]);
    }

    private function seed(User $user): void
    {
        if (CommsUrgentRule::query()->whereBelongsTo($user)->exists()) {
            return;
        }

        $configured = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::UserConfigured)
            ->where('normalized->kind', 'urgent_rule')
            ->exists();

        if ($configured) {
            return;
        }

        $normalized = [
            'kind' => 'urgent_rule',
            'op' => 'set',
            'rule' => [
                'kind' => 'person_weight',
                'ref' => '35',
                'sources' => [],
                'active' => true,
            ],
        ];

        $this->log->append(
            user: $user,
            type: EventType::UserConfigured,
            source: Source::User,
            externalId: 'urgent:set:person_weight:35',
            payload: null,
            normalized: $normalized,
            causation: null,
            occurredAt: now(),
            revision: (string) Str::uuid(),
        );

        // Inside the consumer the log does not dispatch, so the row is written here too.
        $this->apply($user, $normalized);
    }

    private function ruleMatches(User $user, CommsMessage $message, CommsUrgentRule $rule): bool
    {
        if (! $this->appliesToSource($rule, $message)) {
            return false;
        }

        return match ($rule->kind) {
            'person' => $this->person($user, $message, $rule->ref),
            'keyword' => $this->keyword($message->text, $rule->ref),
            'channel' => $rule->ref === $message->source->value.':'.$message->channel_ref,
            'person_weight' => $this->personWeight($message, (int) $rule->ref),
            default => false,
        };
    }

    private function appliesToSource(CommsUrgentRule $rule, CommsMessage $message): bool
    {
        $sources = $rule->sources ?? [];

        return $sources === [] || in_array($message->source->value, $sources, true);
    }

    private function person(User $user, CommsMessage $message, string $ref): bool
    {
        if ((string) $message->author_person_id === $ref) {
            return true;
        }

        $person = $this->people->find($user, $ref);

        return $person !== null && $message->author_person_id === $person->id;
    }

    /**
     * A Slack direct message. Those channels use an id that starts with D.
     */
    private function personWeight(CommsMessage $message, int $minimum): bool
    {
        if ($message->direction !== 'in' || $message->source !== Source::Slack) {
            return false;
        }

        if (! str_starts_with((string) $message->channel_ref, 'D')) {
            return false;
        }

        return ($message->author?->importance_weight ?? 0) >= $minimum;
    }

    private function keyword(string $text, string $keyword): bool
    {
        $keyword = Str::ascii(Str::lower(trim($keyword)));

        if ($keyword === '') {
            return false;
        }

        $text = Str::ascii(Str::lower($text));

        return preg_match('/(?<![a-z0-9])'.preg_quote($keyword, '/').'(?![a-z0-9])/', $text) === 1;
    }
}
