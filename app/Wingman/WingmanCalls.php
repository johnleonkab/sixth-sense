<?php

namespace App\Wingman;

use App\Events\WingmanMeetingEnded;
use App\Events\WingmanMeetingStarted;
use App\Events\WingmanMeetingUpdated;
use App\Jobs\PrepareWingmanContext;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Wingman\Notes\LiveNotes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the extension tells of a call: the person joined it, what is being
 * said, the person left it.
 */
class WingmanCalls
{
    /** How the person's own captions are named, by Meet's language. */
    private const ME = ['tú', 'tu', 'you'];

    /** A call is matched to a calendar meeting starting within this margin of joining. */
    private const MATCH_HOURS = 12;

    public function __construct(
        private WingmanMeetings $meetings,
        private LiveNotes $notes,
    ) {}

    /**
     * The person joined the call with this code: its meeting from the calendar
     * (looked up again if it is not known yet), or one made for a call that
     * is in no calendar.
     */
    public function start(User $user, string $code, CarbonImmutable $joinedAt): WingmanMeeting
    {
        $meeting = $this->meetingFor($user, $code, $joinedAt);

        if ($meeting === null) {
            try {
                $this->meetings->sync($user);
            } catch (Throwable $exception) {
                report($exception);
            }

            $meeting = $this->meetingFor($user, $code, $joinedAt);
        }

        $meeting ??= $user->wingmanMeetings()->create([
            'event_key' => "adhoc:{$code}@{$joinedAt->utc()->toIso8601String()}",
            'meeting_code' => $code,
            'meeting_url' => "https://meet.google.com/{$code}",
            'title' => "Llamada de Meet ({$code})",
            'starts_at' => $joinedAt,
            'ends_at' => $joinedAt->addHour(),
            'attendees' => [],
        ]);

        $meeting->update([
            'status' => MeetingStatus::Live,
            // Joining again after dropping keeps the first time in.
            'joined_at' => $meeting->joined_at ?? $joinedAt,
            'left_at' => null,
            // A new call starts listening, even if the last one was paused.
            'paused_at' => $meeting->status === MeetingStatus::Live ? $meeting->paused_at : null,
        ]);

        if ($meeting->context_prepared_at === null) {
            PrepareWingmanContext::dispatch($meeting);
        }

        WingmanMeetingStarted::dispatch($meeting);

        return $meeting;
    }

    /**
     * What is being said. A segment comes again as it grows: the latest wins.
     *
     * @param  list<array{client_id: string, speaker: string, text: string, final?: bool, spoken_at: string}>  $segments
     * @return int Segments taken.
     */
    public function record(WingmanMeeting $meeting, array $segments): int
    {
        // Paused by the person: what is said meanwhile is not kept.
        if ($segments === [] || $meeting->paused_at !== null) {
            return 0;
        }

        $now = now();

        $meeting->segments()->getQuery()->upsert(array_map(fn (array $segment): array => [
            'wingman_meeting_id' => $meeting->id,
            'client_id' => $segment['client_id'],
            'speaker' => Str::limit(trim($segment['speaker']), 120, ''),
            'is_me' => in_array(Str::lower(trim($segment['speaker'])), self::ME, true),
            'text' => trim($segment['text']),
            'final' => (bool) ($segment['final'] ?? false),
            'spoken_at' => CarbonImmutable::parse($segment['spoken_at'])->utc(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $segments), ['wingman_meeting_id', 'client_id'], ['speaker', 'is_me', 'text', 'final', 'updated_at']);

        // Still going on: what keeps it from being taken as abandoned.
        $meeting->touch();
        $this->notes->maybeTake($meeting);

        return count($segments);
    }

    /**
     * The person left the call.
     */
    public function end(WingmanMeeting $meeting, CarbonImmutable $leftAt): WingmanMeeting
    {
        $meeting->update(['status' => MeetingStatus::Ended, 'left_at' => $leftAt, 'paused_at' => null]);
        $meeting->segments()->getQuery()->where('final', false)->update(['final' => true]);
        // The last lines into the notes, now.
        $this->notes->maybeTake($meeting, now: true);

        WingmanMeetingEnded::dispatch($meeting);

        return $meeting;
    }

    private function meetingFor(User $user, string $code, CarbonImmutable $joinedAt): ?WingmanMeeting
    {
        return $user->wingmanMeetings()
            ->where('meeting_code', $code)
            ->whereBetween('starts_at', [$joinedAt->subHours(self::MATCH_HOURS)->utc(), $joinedAt->addHours(self::MATCH_HOURS)->utc()])
            ->get()
            ->sortBy(fn (WingmanMeeting $meeting): int => abs($meeting->starts_at->diffInSeconds($joinedAt, false)))
            ->first();
    }

    /**
     * Stop keeping what is said, or start again.
     */
    public function pause(WingmanMeeting $meeting, bool $paused): WingmanMeeting
    {
        $meeting->update(['paused_at' => $paused ? now() : null]);

        WingmanMeetingUpdated::dispatch($meeting);

        return $meeting;
    }
}
