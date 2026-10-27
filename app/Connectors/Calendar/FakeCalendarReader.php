<?php

namespace App\Connectors\Calendar;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Sample day from docs/prototype.html, for tests and demo mode.
 */
class FakeCalendarReader implements CalendarReader
{
    /** @var list<EventQuery> */
    public array $queries = [];

    /** @var list<CalendarEvent> A scripted day, instead of the sample, for tests. */
    public array $scripted = [];

    public function read(User $user, EventQuery $query): CalendarReading
    {
        $this->queries[] = $query;

        if ($this->scripted !== []) {
            return new CalendarReading(
                events: array_values(array_filter(
                    $this->scripted,
                    fn (CalendarEvent $event): bool => $query->matches($event) && $event->end->greaterThan($query->from) && $event->start->lessThan($query->to),
                )),
                accounts: ['demo@sixth-sense.test'],
                calendars: [['account' => 'demo@sixth-sense.test', 'name' => 'demo@sixth-sense.test', 'color' => null]],
            );
        }

        $events = [];

        for ($day = $query->from; $day->lessThanOrEqualTo($query->to); $day = $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }

            foreach ($this->sampleDay() as $index => [$from, $to, $title, $attendees]) {
                $event = new CalendarEvent(
                    id: $day->format('Ymd').'-'.$index,
                    title: $title,
                    start: $day->setTimeFromTimeString($from),
                    end: $day->setTimeFromTimeString($to),
                    allDay: false,
                    account: 'demo@sixth-sense.test',
                    calendar: 'demo@sixth-sense.test',
                    attendees: array_map(fn (string $name): array => ['name' => $name, 'email' => Str::lower(Str::ascii(strtok($name, ' '))).'@covermanager.test', 'response' => 'accepted'], $attendees),
                    meetingUrl: $index === 4 ? null : 'https://meet.google.com/'.['dly-prod-uct', 'pre-pago-rev', 'one-oone-mrt', 'syn-pven-tas'][$index],
                    ref: 'demo:primary:'.$day->format('Ymd').'-'.$index,
                    description: $index === 1 ? "Revisamos dónde está CPD-412 antes del sprint.\nPlan: https://www.notion.so/covermanager/Plan-de-lanzamiento-prepago-1a2b3c4d000040008000000000000001" : null,
                    isOrganizer: in_array($index, [2, 3], true),
                    myResponse: in_array($index, [2, 3], true) ? 'accepted' : 'needsAction',
                );

                if ($query->matches($event)) {
                    $events[] = $event;
                }
            }
        }

        return new CalendarReading(
            events: $events,
            accounts: ['demo@sixth-sense.test'],
            calendars: [['account' => 'demo@sixth-sense.test', 'name' => 'demo@sixth-sense.test', 'color' => null]],
        );
    }

    /** @var array<string, list<array{CarbonImmutable, CarbonImmutable}>> Busy times of other people, for tests. */
    public array $othersBusy = [];

    public function busy(User $user, array $emails, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_intersect_key($this->othersBusy, array_flip($emails));
    }

    public function event(User $user, string $ref, string $timezone): ?CalendarEvent
    {
        if (! preg_match('/^demo:primary:(\d{8})-(\d+)$/', $ref, $match)) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('Ymd', $match[1], $timezone)->startOfDay();

        foreach ($this->read($user, new EventQuery($day, $day->endOfDay()))->events as $event) {
            if ($event->ref === $ref) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return list<array{string, string, string, list<string>}>
     */
    private function sampleDay(): array
    {
        return [
            ['09:30', '09:45', 'Daily de producto', ['Marta Ruiz', 'Pablo Gil']],
            ['11:00', '11:45', 'Revisión flujo de prepago', ['Marta Ruiz']],
            ['12:00', '12:30', '1:1 con Marta', ['Marta Ruiz']],
            ['13:00', '14:00', 'Sync producto–ventas', ['Pablo Gil', 'Lucía Romero']],
            ['16:30', '17:15', 'Grupo Vela: contrato', ['Andrés Vela']],
        ];
    }
}
