<?php

namespace App\Scheduling;

use App\Models\AgendaTask;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * How long the person's calls with the same people usually last: after three
 * booked the same way, it stops asking; once they change it, it asks again.
 */
class UsualDurations
{
    private const SAME_TIMES = 3;

    /**
     * @param  list<string>  $people  Names, as the classifier gives them.
     */
    public function forCall(User $user, array $people): ?int
    {
        $key = self::key($people);

        if ($key === '') {
            return null;
        }

        $durations = $user->agendaTasks()
            ->where('kind', 'meeting')
            ->where('status', AgendaTaskStatus::Scheduled)
            ->whereNotNull('duration_minutes')
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (AgendaTask $task): bool => self::key(array_map(fn (array $person): string => (string) ($person['name'] ?? $person['email'] ?? ''), $task->attendees ?? [])) === $key)
            ->take(self::SAME_TIMES)
            ->pluck('duration_minutes');

        return $durations->count() === self::SAME_TIMES && $durations->unique()->count() === 1 ? (int) $durations->first() : null;
    }

    /**
     * The same people however they are written: first names, lower case, in order.
     *
     * @param  list<string>  $people
     */
    private static function key(array $people): string
    {
        $names = array_filter(array_map(fn (string $name): string => Str::lower(Str::ascii((string) strtok(trim($name), ' <@'))), $people));
        sort($names);

        return implode(',', array_unique($names));
    }
}
