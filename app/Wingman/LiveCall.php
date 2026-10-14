<?php

namespace App\Wingman;

use App\Models\User;
use App\Models\WingmanMeeting;
use App\Models\WingmanSegment;
use App\Wingman\Notes\MeetingNotes;

/**
 * The call the person is in, as Wingman's bubble shows it: what it is,
 * whether Wingman is listening, the last things said and the context at hand.
 */
class LiveCall
{
    /** Lines of what was said that the bubble shows. */
    public const LINES = 6;

    /**
     * @return array<string, mixed>|null
     */
    public function for(User $user): ?array
    {
        $meeting = $user->wingmanMeetings()->where('status', MeetingStatus::Live)->latest('joined_at')->first();

        return $meeting ? $this->describe($meeting, self::LINES) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(WingmanMeeting $meeting, int $lines): array
    {
        $context = $meeting->context ?? [];
        $notes = MeetingNotes::fromArray($meeting->notes);

        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'meeting_url' => $meeting->meeting_url,
            'status' => $meeting->status->value,
            'joined_at' => $meeting->joined_at?->toIso8601String(),
            'paused' => $meeting->paused_at !== null,
            'segment_count' => $meeting->segments()->count(),
            'attendees' => collect($meeting->attendees ?? [])->pluck('name')->filter()->values()->all(),
            'lines' => $meeting->segments()->getQuery()->reorder()->latest('spoken_at')->latest('id')->limit($lines)->get()->reverse()->values()
                ->map(fn (WingmanSegment $segment): array => [
                    'id' => $segment->id,
                    'speaker' => $segment->is_me ? 'Tú' : $segment->speaker,
                    'is_me' => $segment->is_me,
                    'text' => $segment->text,
                    'final' => $segment->final,
                ])->all(),
            'issues' => collect($context['issues'] ?? [])->map(fn (array $issue): array => [
                'ref' => $issue['ref'],
                'key' => $issue['key'],
                'summary' => $issue['summary'],
                'status' => $issue['status'],
                'status_category' => $issue['status_category'],
                'url' => $issue['url'] ?? null,
            ])->all(),
            'pages' => collect($context['pages'] ?? [])->map(fn (array $page): array => ['ref' => $page['ref'], 'title' => $page['title'], 'url' => $page['url'] ?? null])->all(),
            'prepared' => $meeting->context_prepared_at !== null,
            'summary' => $notes->summary,
            'note_count' => $notes->count(),
            'open_questions' => array_slice($notes->questions, -3),
        ];
    }
}
