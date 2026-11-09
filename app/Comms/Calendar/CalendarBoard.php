<?php

namespace App\Comms\Calendar;

use App\Models\Comms\CommsCalendarEvent;
use App\Models\Comms\CommsEvent;

/**
 * Calendar rows are a projection of source.changed events. The clock reads them.
 */
class CalendarBoard
{
    public function project(CommsEvent $event): void
    {
        $data = $event->normalized ?? [];
        $user = $event->user;

        if ($user === null || ! isset($data['starts_at'], $data['ends_at'])) {
            return;
        }

        $title = (string) ($data['title'] ?? $data['text'] ?? 'Reunión');
        $kind = $this->kind((string) ($data['kind'] ?? 'calendar'), $title);

        if ($kind === null) {
            return;
        }

        CommsCalendarEvent::query()->updateOrCreate(
            ['user_id' => $user->id, 'external_id' => (string) ($data['ref'] ?? $event->external_id)],
            [
                'title' => $title,
                'kind' => $kind,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'attendees' => array_values($data['attendees'] ?? []),
                'status' => (string) ($data['status'] ?? 'confirmed'),
                'agenda' => isset($data['agenda']) ? (string) $data['agenda'] : null,
                'recurring' => (bool) ($data['recurring'] ?? false),
            ],
        );
    }

    private function kind(string $kind, string $title): ?string
    {
        if ($kind === 'calendar' || $kind === 'focus') {
            return $this->classified($title, $kind === 'focus' ? 'focus' : 'meeting');
        }

        return null;
    }

    private function classified(string $title, string $fallback): string
    {
        $haystack = mb_strtolower($title);
        $patterns = [
            'focus' => config('comms.calendar.focus_titles', []),
            'planning' => config('comms.calendar.planning_titles', []),
            'one_on_one' => config('comms.calendar.one_on_one_titles', []),
            'interview' => config('comms.calendar.interview_titles', []),
            'demo' => config('comms.calendar.demo_titles', []),
        ];

        foreach ($patterns as $kind => $needles) {
            if (! is_array($needles)) {
                continue;
            }

            foreach ($needles as $needle) {
                if (is_string($needle) && $needle !== '' && str_contains($haystack, mb_strtolower($needle))) {
                    return $kind;
                }
            }
        }

        return $fallback;
    }
}
