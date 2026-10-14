<?php

namespace App\Wingman\Copilot;

use App\Events\WingmanMeetingUpdated;
use App\Jobs\WatchWingmanCall;
use App\Models\WingmanInsight;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Wingman's agent: every little while it reads what was just said in a
 * call, has the detector say what is worth looking into, checks it with
 * real data and brings in what helps. Only what adds something, never the
 * same thing twice, and not too often.
 */
class WingmanAgent
{
    /** Lines said just before the new ones, for context. */
    private const EARLIER = 12;

    public function __construct(
        private Detector $detector,
        private Lookups $lookups,
    ) {}

    /**
     * Read the call if new lines are finished and the last reading is old enough.
     */
    public function maybeWatch(WingmanMeeting $meeting): void
    {
        $due = $meeting->watched_at === null || $meeting->watched_at->lt(now()->subSeconds((int) config('wingman.agent.every_seconds')));

        if ($due && $this->unwatched($meeting)->exists()) {
            WatchWingmanCall::dispatch($meeting);
        }
    }

    /**
     * Read what is new and bring in what helps.
     *
     * @return list<WingmanInsight> What was brought in.
     */
    public function watch(WingmanMeeting $meeting): array
    {
        $new = $this->unwatched($meeting)->orderBy('spoken_at')->orderBy('id')->limit((int) config('wingman.agent.max_lines'))->get();

        if ($new->isEmpty()) {
            return [];
        }

        $earlier = WingmanSegment::query()
            ->where('wingman_meeting_id', $meeting->id)
            ->where('watched', true)
            ->latest('spoken_at')->latest('id')
            ->limit(self::EARLIER)
            ->get()
            ->reverse();

        $findings = $this->detector->detect($meeting, $this->lines($earlier), $this->lines($new), $meeting->insights()->pluck('title')->all());

        WingmanSegment::query()->whereKey($new->modelKeys())->update(['watched' => true]);
        $meeting->update(['watched_at' => now()]);

        $brought = [];

        foreach (collect($findings)->sortByDesc(fn (Finding $finding): float => ($finding->important ? 1 : 0) + $finding->confidence) as $finding) {
            if (count($brought) >= (int) config('wingman.agent.max_per_reading') || $finding->confidence < (float) config('wingman.agent.min_confidence')) {
                continue;
            }

            if (! $finding->important && $this->spokeRecently($meeting)) {
                continue;
            }

            $card = $this->lookups->resolve($meeting, $finding);

            if ($card === null || $meeting->insights()->where('dedupe_key', $card['dedupe_key'])->exists()) {
                continue;
            }

            $brought[] = $meeting->insights()->create($card);
        }

        if ($brought !== []) {
            WingmanMeetingUpdated::dispatch($meeting);
        }

        return $brought;
    }

    /**
     * Something not urgent waits if Wingman brought something in a moment ago.
     */
    private function spokeRecently(WingmanMeeting $meeting): bool
    {
        return $meeting->insights()->where('created_at', '>', now()->subSeconds((int) config('wingman.agent.quiet_seconds')))->exists();
    }

    /**
     * @return Builder<WingmanSegment>
     */
    private function unwatched(WingmanMeeting $meeting): Builder
    {
        return WingmanSegment::query()->where('wingman_meeting_id', $meeting->id)->where('final', true)->where('watched', false);
    }

    /**
     * @param  Collection<int, WingmanSegment>  $segments
     * @return list<array{at: string, speaker: string, is_me: bool, text: string}>
     */
    private function lines(Collection $segments): array
    {
        $timezone = (string) config('agenda.timezone');

        return $segments->map(fn (WingmanSegment $segment): array => [
            'at' => $segment->spoken_at->setTimezone($timezone)->format('H:i'),
            'speaker' => $segment->is_me ? 'Tú' : $segment->speaker,
            'is_me' => $segment->is_me,
            'text' => $segment->text,
        ])->values()->all();
    }
}
