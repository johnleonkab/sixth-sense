<?php

namespace App\Wingman\Notes;

use App\Events\WingmanMeetingUpdated;
use App\Jobs\TakeWingmanNotes;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps a call's notes in step with what is said: every little while, the
 * finished lines not yet in the notes go to the note-taker in one batch.
 */
class LiveNotes
{
    /** Batches per run at most: a long backlog is taken in a few passes. */
    private const PASSES = 3;

    public function __construct(private NoteTaker $taker) {}

    /**
     * Ask for notes if new lines are finished and the last notes are old enough
     * (or right away, at the end of the call).
     */
    public function maybeTake(WingmanMeeting $meeting, bool $now = false): void
    {
        $due = $now || $meeting->notes_updated_at === null || $meeting->notes_updated_at->lt(now()->subSeconds((int) config('wingman.notes.every_seconds')));

        if ($due && $this->pending($meeting)->exists()) {
            TakeWingmanNotes::dispatch($meeting);
        }
    }

    /**
     * Take the finished lines into the notes.
     *
     * @return int Lines taken.
     */
    public function take(WingmanMeeting $meeting): int
    {
        $taken = 0;
        $timezone = (string) config('agenda.timezone');

        for ($pass = 0; $pass < self::PASSES; $pass++) {
            $segments = $this->pending($meeting)->orderBy('spoken_at')->orderBy('id')->limit((int) config('wingman.notes.max_lines'))->get();

            if ($segments->isEmpty()) {
                break;
            }

            $notes = $this->taker->update($meeting, MeetingNotes::fromArray($meeting->notes), $segments->map(fn (WingmanSegment $segment): array => [
                'at' => $segment->spoken_at->setTimezone($timezone)->format('H:i'),
                'speaker' => $segment->is_me ? 'Tú' : $segment->speaker,
                'is_me' => $segment->is_me,
                'text' => $segment->text,
            ])->all());

            $meeting->update(['notes' => $notes->toArray(), 'notes_updated_at' => now()]);
            WingmanSegment::query()->whereKey($segments->modelKeys())->update(['noted' => true]);
            $taken += $segments->count();
        }

        if ($taken > 0) {
            WingmanMeetingUpdated::dispatch($meeting);
        }

        return $taken;
    }

    /**
     * Finished lines not yet in the notes.
     *
     * @return Builder<WingmanSegment>
     */
    private function pending(WingmanMeeting $meeting): Builder
    {
        return WingmanSegment::query()->where('wingman_meeting_id', $meeting->id)->where('final', true)->where('noted', false);
    }
}
