<?php

namespace App\Comms\Context;

use App\Knowledge\Gaps\GapKind;
use App\Models\Comms\CommsContextFact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A lower producer that keeps contradicting what the person said is not
 * applied, but after enough times over enough days it is worth one question.
 * The question goes through the knowledge-gap queue, so it shares its limits
 * (three a day, working hours) and its answer is a user fact.
 */
class ContextQuestions
{
    public function __construct(private ContextRenderer $renderer) {}

    /**
     * @return int Questions opened now.
     */
    public function find(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $opened = 0;

        $proposed = CommsContextFact::query()
            ->whereBelongsTo($user)
            ->where('status', 'proposed')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (CommsContextFact $fact): string => $fact->subject.'|'.$fact->attribute.'|'.$fact->value_hash);

        foreach ($proposed as $group) {
            $first = $group->first();
            $latest = $group->last();

            if ($group->count() < (int) config('context.questions_after')
                || $first->created_at->greaterThan($now->subDays((int) config('context.questions_over_days')))) {
                continue;
            }

            $current = CommsContextFact::query()
                ->whereBelongsTo($user)
                ->where('subject', $latest->subject)
                ->where('attribute', $latest->attribute)
                ->where('status', 'active')
                ->first();

            $key = 'fact:'.sha1($latest->subject.'|'.$latest->attribute.'|'.$latest->value_hash);

            if ($current === null || $user->knowledgeGaps()->where('kind', GapKind::Fact)->where('subject_key', $key)->exists()) {
                continue;
            }

            $name = $this->renderer->name(['subject' => $latest->subject, 'value' => $latest->value ?? []]);
            $user->knowledgeGaps()->create([
                'kind' => GapKind::Fact,
                'subject_key' => $key,
                'subject' => Str::limit($name, 250),
                'question' => "¿{$name}, {$latest->attribute}: «{$latest->text()}»? Me dijiste «{$current->text()}».",
                'context' => ['fact_id' => $latest->id, 'times' => $group->count()],
                'priority' => $group->count(),
            ]);
            $opened++;
        }

        return $opened;
    }
}
