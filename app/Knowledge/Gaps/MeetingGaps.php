<?php

namespace App\Knowledge\Gaps;

use App\Connectors\Calendar\CalendarEvent;
use App\Connectors\Calendar\CalendarReader;
use App\Connectors\Calendar\EventQuery;
use App\Knowledge\KnowledgeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Looks at the week ahead for meetings whose purpose nothing explains: no
 * description, no document, no past notes, no earlier answer. Each one (a
 * recurring series counts once) becomes a question.
 */
class MeetingGaps
{
    /** How far ahead meetings are looked at. */
    private const DAYS = 7;

    /** A description shorter than this does not explain a meeting. */
    private const EXPLAINED = 60;

    public function __construct(private CalendarReader $calendar) {}

    /**
     * @return int Questions noticed in this look.
     */
    public function find(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $series = [];

        foreach ($this->calendar->read($user, new EventQuery($now, $now->addDays(self::DAYS)))->events as $event) {
            if (! $this->isMeeting($event)) {
                continue;
            }

            $key = self::seriesKey($event);
            $series[$key]['events'][] = $event;
        }

        $noticed = 0;

        foreach ($series as $key => $group) {
            /** @var list<CalendarEvent> $events */
            $events = $group['events'];
            usort($events, fn (CalendarEvent $a, CalendarEvent $b): int => $a->start <=> $b->start);
            $next = $events[0];

            if ($user->knowledgeGaps()->where('kind', GapKind::Meeting)->where('subject_key', $key)->exists() || $this->explained($user, $events)) {
                continue;
            }

            $user->knowledgeGaps()->create([
                'kind' => GapKind::Meeting,
                'subject_key' => mb_substr($key, 0, 191),
                'subject' => Str::limit($next->title, 250),
                'question' => $this->question($next, count($events)),
                'context' => [
                    'title' => $next->title,
                    'organizer' => $next->isOrganizer ? 'tú' : $next->organizer,
                    'attendees' => array_slice(array_map(fn (array $attendee): string => $attendee['name'] ?: (string) $attendee['email'], $next->attendees), 0, 10),
                    'recurring' => count($events) > 1,
                    'starts_at' => $next->start->toIso8601String(),
                ],
                'status' => GapStatus::Open,
                'priority' => max(1, 100 - (int) $now->diffInDays($next->start)),
                'next_occurrence_at' => $next->start,
            ]);
            $noticed++;
        }

        return $noticed;
    }

    /**
     * Every instance of a recurring meeting shares its iCalUID; the reader
     * adds the start time after the last "@".
     */
    public static function seriesKey(CalendarEvent $event): string
    {
        return $event->uniqueKey !== null ? Str::beforeLast($event->uniqueKey, '@') : $event->id;
    }

    /**
     * With somebody else, timed, not declined and not a block of the person's own.
     */
    private function isMeeting(CalendarEvent $event): bool
    {
        return ! $event->allDay
            && ! $event->declined
            && ! $event->flexible
            && $event->myResponse !== 'declined'
            && count($event->attendees) >= 2;
    }

    /**
     * @param  list<CalendarEvent>  $events
     */
    private function explained(User $user, array $events): bool
    {
        foreach ($events as $event) {
            if (mb_strlen(trim(strip_tags((string) $event->description))) >= self::EXPLAINED || $event->documents !== []) {
                return true;
            }
        }

        $title = trim($events[0]->title);

        return $title !== '' && $user->knowledgeDocuments()
            ->where('source', KnowledgeSource::Meeting)
            ->whereRaw('lower(title) like ?', ['%'.mb_strtolower($title).'%'])
            ->exists();
    }

    private function question(CalendarEvent $next, int $occurrences): string
    {
        $when = $next->start->setTimezone(config('agenda.timezone'))->locale('es')->isoFormat('dddd D [a las] HH:mm');
        $people = count($next->attendees);
        $organizer = $next->isOrganizer ? 'la organizas tú' : ($next->organizer ? "la organiza {$next->organizer}" : null);
        $repeat = $occurrences > 1 ? "Se repite ({$occurrences} veces esta semana); la próxima es el {$when}" : "Es el {$when}";

        return "¿Para qué es «{$next->title}»? {$repeat}, con {$people} personas".($organizer ? ", y {$organizer}" : '').'. '
            .'No tiene descripción, documentos ni notas. Dime de qué va, qué papel tienes y si te hace falta ir.';
    }
}
