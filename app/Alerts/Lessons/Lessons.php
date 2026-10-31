<?php

namespace App\Alerts\Lessons;

use App\Models\IntentLesson;
use App\Models\ProposalOutcome;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the person's corrections taught, for each layer that reads it: the
 * importance judge, the triage, the classifier, the reply engine and the
 * scheduler. A lesson about someone comes first when the message is theirs.
 * A newer lesson on the same topic replaces the older one (it contradicts
 * it); a lesson unused for a long while stops being read. Lessons only ever
 * add context: the rules (the "sí", autonomy, the tools) never change.
 */
class Lessons
{
    /** Read at most this many, so the instructions stay short. */
    private const LIMIT = 8;

    /** Unused this long, a lesson is no longer read. */
    private const STALE_DAYS = 60;

    /**
     * Keep a lesson, replacing an older one on the same topic.
     */
    public function learn(User $user, string $body, ?string $layer = null, ?string $about = null, ?string $topic = null, ?int $alertId = null, ?ProposalOutcome $outcome = null): IntentLesson
    {
        if ($topic !== null) {
            $user->intentLessons()->where('layer', $layer)->where('about', $about)->where('topic', $topic)->delete();
        }

        return $user->intentLessons()->create([
            'inbox_item_id' => $alertId ?? $outcome?->inbox_item_id,
            'proposal_outcome_id' => $outcome?->id,
            'layer' => $layer,
            'about' => $about !== null ? Str::limit($about, 120, '') : null,
            'topic' => $topic,
            'body' => Str::limit($body, 240),
        ]);
    }

    /**
     * The lessons a layer reads now, marked as used.
     *
     * @return Collection<int, IntentLesson>
     */
    public function for(User $user, string $layer, ?string $about = null): Collection
    {
        $first = $about !== null ? Str::lower((string) strtok(trim($about), ' <')) : null;
        $lessons = $user->intentLessons()
            ->where(fn ($query) => $query->whereNull('layer')->orWhere('layer', $layer))
            ->where(fn ($query) => $query->where('last_used_at', '>=', now()->subDays(self::STALE_DAYS))
                ->orWhere(fn ($fresh) => $fresh->whereNull('last_used_at')->where('created_at', '>=', now()->subDays(self::STALE_DAYS))))
            ->latest('id')
            ->limit(50)
            ->get()
            ->sortBy(fn (IntentLesson $lesson): int => $first !== null && $lesson->about !== null && str_contains(Str::lower($lesson->about), $first) ? 0 : 1)
            ->take(self::LIMIT)
            ->values();

        if ($lessons->isNotEmpty()) {
            IntentLesson::query()->whereKey($lessons->modelKeys())->incrementEach(['uses' => 1], ['last_used_at' => now()]);
        }

        return $lessons;
    }

    /**
     * The same, as a block to append to a model's instructions.
     */
    public function render(User $user, string $layer, ?string $about = null): string
    {
        $lessons = $this->for($user, $layer, $about)->pluck('body');

        return $lessons->isEmpty() ? '' : "\nAprendido de avisos anteriores (datos):\n- ".$lessons->implode("\n- ");
    }
}
