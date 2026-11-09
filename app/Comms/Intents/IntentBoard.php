<?php

namespace App\Comms\Intents;

use App\Comms\People\Relation;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsPerson;
use App\Models\User;

/**
 * The three groups on the resting screen.
 */
class IntentBoard
{
    public function __construct(private MessageUrl $urls) {}

    /**
     * @return array{
     *     waiting_on_me: list<array<string, mixed>>,
     *     waiting_on_others: list<array<string, mixed>>,
     *     quiet: list<array<string, mixed>>
     * }
     */
    public function for(User $user): array
    {
        $selfId = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->value('id');
        $groups = [
            'waiting_on_me' => [],
            'waiting_on_others' => [],
            'quiet' => [],
        ];

        $intents = CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('state', 'open')
            ->with(['participants.person', 'links.message'])
            ->orderByDesc('importance')
            ->orderByDesc('last_activity_at')
            ->get();

        foreach ($intents as $intent) {
            $group = $this->group($intent, $selfId);
            $groups[$group][] = $this->row($intent, $group, $selfId);
        }

        return $groups;
    }

    private function group(CommsIntent $intent, mixed $selfId): string
    {
        $activity = $intent->last_activity_at;
        $quietAfter = (int) config('comms.quiet_after_days', 7);

        if ($activity === null || $activity->lt(now()->subDays($quietAfter))) {
            return 'quiet';
        }

        $requesters = $intent->participants->where('role', 'requester')->pluck('person_id');
        $responders = $intent->participants->where('role', 'responder')->pluck('person_id');
        $possible = $intent->participants->where('role', 'possible_responder')->pluck('person_id');
        $userResponds = $selfId !== null && ($responders->contains($selfId) || $possible->contains($selfId));
        $someoneWaiting = $requesters->contains(fn (mixed $id): bool => $id !== $selfId);

        if ($userResponds && $someoneWaiting) {
            return 'waiting_on_me';
        }

        $userAsked = $selfId !== null && $requesters->contains($selfId);
        $someoneElse = $responders->contains(fn (mixed $id): bool => $id !== $selfId)
            || $possible->contains(fn (mixed $id): bool => $id !== $selfId);

        if ($userAsked && $someoneElse) {
            return 'waiting_on_others';
        }

        return 'quiet';
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CommsIntent $intent, string $group, mixed $selfId): array
    {
        $latest = $intent->links
            ->sortByDesc(fn ($link): int => $link->message?->occurred_at?->getTimestamp() ?? 0)
            ->first();
        $uncertain = $intent->links->first(fn ($link): bool => $link->uncertain && $link->message !== null);
        $score = (int) $intent->importance;

        return [
            'key' => $intent->key,
            'what' => $intent->what,
            'who' => $this->who($intent, $group, $selfId),
            'deadline' => $intent->deadline?->toDateString(),
            'last_change' => $intent->last_change_summary,
            'source_url' => $latest?->message !== null ? $this->urls->for($latest->message) : null,
            'importance' => $this->level($score),
            'uncertain' => $uncertain !== null || (bool) ($intent->flags['uncertain'] ?? false),
            'message' => $uncertain?->message === null ? null : [
                'source' => $uncertain->message->source->value,
                'external_id' => $uncertain->message->external_id,
                'text' => mb_substr($uncertain->message->text, 0, 280),
            ],
        ];
    }

    private function who(CommsIntent $intent, string $group, mixed $selfId): string
    {
        $role = $group === 'waiting_on_others' ? 'responder' : 'requester';
        $names = $intent->participants
            ->where('role', $role)
            ->reject(fn ($participant): bool => $participant->person_id === $selfId)
            ->map(fn ($participant): string => (string) $participant->person->display_name)
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) {
            $names = $intent->participants
                ->reject(fn ($participant): bool => $participant->person_id === $selfId)
                ->map(fn ($participant): string => (string) $participant->person->display_name)
                ->filter()
                ->unique()
                ->values();
        }

        return $names->implode(', ');
    }

    private function level(int $score): string
    {
        if ($score >= 50) {
            return 'alta';
        }

        if ($score >= 20) {
            return 'media';
        }

        return 'baja';
    }
}
