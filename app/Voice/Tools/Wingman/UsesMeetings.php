<?php

namespace App\Voice\Tools\Wingman;

use App\Models\User;
use App\Models\WingmanAction;
use App\Models\WingmanMeeting;
use App\Voice\Tools\Canvas;
use App\Wingman\Notes\MeetingNotes;
use Illuminate\Support\Collection;

/**
 * Finding a meeting Wingman followed, and showing its document.
 */
trait UsesMeetings
{
    /**
     * The meeting meant: by its id, by words of its title, or the latest one followed.
     */
    private function meetingNamed(User $user, ?int $id, ?string $title): ?WingmanMeeting
    {
        $followed = $user->wingmanMeetings()->whereNotNull('joined_at');

        if ($id !== null) {
            return (clone $followed)->find($id);
        }

        // By words of its title, its attendees («la de Marta») or what it was about.
        if (filled($title)) {
            return (clone $followed)->latest('joined_at')->limit(200)->get()->first(fn (WingmanMeeting $meeting): bool => $meeting->matches($title));
        }

        return (clone $followed)->latest('joined_at')->first();
    }

    /**
     * What is left to do, numbered as the person hears it (dropped ones apart).
     *
     * @return Collection<int, WingmanAction>
     */
    private function openActions(WingmanMeeting $meeting): Collection
    {
        return $meeting->actions()->with('pendingAction')->get()
            ->reject(fn (WingmanAction $action): bool => $action->state() === 'dismissed')
            ->values();
    }

    /**
     * The meeting's document as a canvas, with a change to confirm when there is one.
     *
     * @param  array<string, mixed>|null  $action
     */
    private function documentCanvas(WingmanMeeting $meeting, ?array $action = null, ?int $highlight = null): Canvas
    {
        $timezone = (string) config('agenda.timezone');

        return new Canvas('wingman.doc', [
            'meeting' => [
                'id' => $meeting->id,
                'title' => $meeting->title,
                'when' => $meeting->starts_at->setTimezone($timezone)->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm'),
                'status' => $meeting->status->value,
                'closed' => $meeting->closed_at !== null,
            ],
            'notes' => MeetingNotes::fromArray($meeting->notes)->toArray(),
            'actions' => $this->openActions($meeting)->values()->map(fn (WingmanAction $item, int $index): array => [...$item->toCard(), 'number' => $index + 1])->all(),
            'highlight' => $highlight,
            'action' => $action,
        ], layout: 'wide');
    }
}
