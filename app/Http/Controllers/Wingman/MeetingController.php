<?php

namespace App\Http\Controllers\Wingman;

use App\Http\Controllers\Controller;
use App\Jobs\PrepareWingmanContext;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use App\Wingman\Notes\MeetingNotes;
use App\Wingman\WingmanMeetings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MeetingController extends Controller
{
    /** Past meetings listed below today's. */
    private const PAST = 30;

    /** The latest things said that a meeting's page shows. */
    private const TRANSCRIPT = 300;

    /**
     * Wingman's meetings: today's (looked up in the calendar as the page opens) and past ones.
     */
    public function index(Request $request, WingmanMeetings $meetings): Response
    {
        $user = $request->user();
        $timezone = (string) config('agenda.timezone');
        $today = CarbonImmutable::now($timezone);

        return Inertia::render('wingman/index', [
            'enabled' => $user->wingman_enabled,
            'today' => Inertia::defer(function () use ($user, $meetings, $today, $timezone): array {
                if ($user->wingman_enabled) {
                    try {
                        $meetings->sync($user);
                    } catch (Throwable $exception) {
                        // The calendar could not answer now: what was found before is shown.
                        report($exception);
                    }
                }

                return $user->wingmanMeetings()
                    ->whereBetween('starts_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
                    ->orderBy('starts_at')
                    ->get()
                    ->map(fn (WingmanMeeting $meeting): array => $this->summary($meeting, $timezone))
                    ->all();
            }),
            'past' => $user->wingmanMeetings()
                ->where('starts_at', '<', $today->startOfDay()->utc())
                ->latest('starts_at')
                ->limit(self::PAST)
                ->get()
                ->map(fn (WingmanMeeting $meeting): array => $this->summary($meeting, $timezone))
                ->all(),
        ]);
    }

    /**
     * One meeting, with what Wingman gathered for it. Opening one not yet prepared prepares it.
     */
    public function show(Request $request, int $meeting): Response
    {
        $meeting = $request->user()->wingmanMeetings()->findOrFail($meeting);

        if ($meeting->context_prepared_at === null) {
            PrepareWingmanContext::dispatch($meeting);
        }

        return Inertia::render('wingman/show', [
            'meeting' => [
                ...$this->summary($meeting, (string) config('agenda.timezone')),
                'description' => $meeting->description,
                'attendees' => $meeting->attendees ?? [],
                'context' => $meeting->context,
                'context_prepared_at' => $meeting->context_prepared_at?->toIso8601String(),
                'paused' => $meeting->paused_at !== null,
                'notes' => $meeting->notes === null ? null : MeetingNotes::fromArray($meeting->notes)->toArray(),
                'notes_updated_at' => $meeting->notes_updated_at?->toIso8601String(),
                'transcript' => $meeting->segments()->getQuery()->reorder()->latest('spoken_at')->latest('id')->limit(self::TRANSCRIPT)->get()->reverse()->values()
                    ->map(fn (WingmanSegment $segment): array => [
                        'id' => $segment->id,
                        'speaker' => $segment->is_me ? 'Tú' : $segment->speaker,
                        'is_me' => $segment->is_me,
                        'text' => $segment->text,
                        'at' => $segment->spoken_at->setTimezone((string) config('agenda.timezone'))->format('H:i'),
                    ])->all(),
            ],
        ]);
    }

    /**
     * Gather the context again (the calendar or the issues changed since).
     */
    public function prepare(Request $request, int $meeting): RedirectResponse
    {
        $meeting = $request->user()->wingmanMeetings()->findOrFail($meeting);
        $meeting->update(['context_prepared_at' => null]);
        PrepareWingmanContext::dispatch($meeting);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(WingmanMeeting $meeting, string $timezone): array
    {
        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'starts_at' => $meeting->starts_at->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $meeting->ends_at->setTimezone($timezone)->toIso8601String(),
            'meeting_url' => $meeting->meeting_url,
            'meeting_code' => $meeting->meeting_code,
            'account' => $meeting->account,
            'organizer' => $meeting->organizer,
            'attendee_count' => count($meeting->attendees ?? []),
            'status' => $meeting->status->value,
            'status_label' => $meeting->status->label(),
            'prepared' => $meeting->context_prepared_at !== null,
        ];
    }
}
