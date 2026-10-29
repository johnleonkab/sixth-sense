<?php

namespace App\Knowledge\Gaps;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Events\InboxItemCreated;
use App\Events\InboxItemUpdated;
use App\Knowledge\KnowledgeSync;
use App\Models\KnowledgeGap;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Turns what the system does not know into questions for the person, a few a
 * day and in working hours, as quiet alerts. Meetings come first, the soonest
 * first; then terms, the most repeated first. An answer becomes memory.
 */
class GapQuestions
{
    /** Questions asked per day, at most. */
    public const PER_DAY = 3;

    /** Only between these hours, in the person's time zone. */
    private const FROM_HOUR = 9;

    private const UNTIL_HOUR = 19;

    public function __construct(private KnowledgeSync $sync) {}

    /**
     * @return int Questions asked now.
     */
    public function ask(User $user, ?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(config('agenda.timezone'));

        if (! $user->wantsAlertsFrom(AlertSource::Questions) || $now->hour < self::FROM_HOUR || $now->hour >= self::UNTIL_HOUR) {
            return 0;
        }

        $this->forgetPassed($user, $now);

        $budget = self::PER_DAY - $user->knowledgeGaps()->where('asked_at', '>=', $now->startOfDay())->count();

        if ($budget <= 0) {
            return 0;
        }

        $meetings = $user->knowledgeGaps()->where('status', GapStatus::Open)->where('kind', GapKind::Meeting)->orderBy('next_occurrence_at')->limit($budget)->get();
        $terms = $user->knowledgeGaps()->where('status', GapStatus::Open)->where('kind', GapKind::Term)->orderByDesc('priority')->limit($budget)->get();

        $asked = 0;

        foreach ($meetings->concat($terms)->take($budget) as $gap) {
            $this->raise($gap, $now);
            $asked++;
        }

        return $asked;
    }

    /**
     * Save the person's answer, close the question and make it memory now.
     */
    public function answer(KnowledgeGap $gap, string $answer): void
    {
        $gap->update(['status' => GapStatus::Answered, 'answer' => trim($answer), 'answered_at' => now()]);
        $this->close($gap, AlertStatus::Resolved, 'answered');
        $this->sync->syncMemory($gap->user);
    }

    /**
     * Not worth asking: it is never asked again.
     */
    public function dismiss(KnowledgeGap $gap): void
    {
        $gap->update(['status' => GapStatus::Dismissed]);
        $this->close($gap, AlertStatus::Dismissed, 'dismissed');
    }

    private function raise(KnowledgeGap $gap, CarbonImmutable $now): void
    {
        $item = $gap->user->inboxItems()->create([
            'source' => AlertSource::Questions,
            'external_id' => "gap:{$gap->id}",
            'title' => $gap->kind === GapKind::Meeting ? "¿Para qué es «{$gap->subject}»?" : "¿Qué es «{$gap->subject}»?",
            'body' => $gap->question,
            'why' => $gap->kind === GapKind::Meeting
                ? 'No sé de qué va esta reunión: ni descripción, ni documentos, ni notas.'
                : 'Sale en '.($gap->context['documents'] ?? 'varios').' documentos recientes y no está en tu archivo.',
            'relevance' => 40,
            'status' => AlertStatus::Pending,
            'triage' => Triage::Routine,
            'planning' => false,
            'payload' => ['gap_id' => $gap->id, 'kind' => $gap->kind->value, 'context' => $gap->context],
            'received_at' => $now,
        ]);

        $gap->update(['status' => GapStatus::Asked, 'asked_at' => $now, 'inbox_item_id' => $item->id]);
        InboxItemCreated::dispatch($item);
    }

    private function close(KnowledgeGap $gap, AlertStatus $status, string $resolution): void
    {
        $item = $gap->inboxItem;

        if ($item === null || $item->status !== AlertStatus::Pending) {
            return;
        }

        $item->close($status, $resolution);
        InboxItemUpdated::dispatch($item);
    }

    /**
     * A one-off meeting that already happened is not worth asking about.
     */
    private function forgetPassed(User $user, CarbonImmutable $now): void
    {
        $user->knowledgeGaps()
            ->where('status', GapStatus::Open)
            ->where('kind', GapKind::Meeting)
            ->where('next_occurrence_at', '<', $now)
            ->get()
            ->reject(fn (KnowledgeGap $gap): bool => (bool) ($gap->context['recurring'] ?? false))
            ->each(fn (KnowledgeGap $gap) => $gap->update(['status' => GapStatus::Dismissed]));
    }
}
