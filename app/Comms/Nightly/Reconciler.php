<?php

namespace App\Comms\Nightly;

use App\Comms\Linking\Schemas;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPreference;
use App\Models\Comms\CommsReconcileChange;
use App\Models\Comms\CommsTopicSearch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Re-reads each topic touched on one day. Stores the proposed changes and
 * does not apply them.
 */
class Reconciler
{
    public function __construct(private ModelRouter $router) {}

    public function run(User $user, ?CarbonImmutable $day = null): int
    {
        $day = $this->day($user, $day);
        $topics = $this->topics($user, $day);

        CommsReconcileChange::query()
            ->whereBelongsTo($user)
            ->whereDate('run_date', $day->toDateString())
            ->where('disposition', 'proposed')
            ->delete();

        $stored = 0;

        foreach ($topics as $topic => $links) {
            $stored += $this->read($user, $day, (string) $topic, $links);
        }

        return $stored;
    }

    private function day(User $user, ?CarbonImmutable $day): CarbonImmutable
    {
        $timezone = $this->timezone($user);

        if ($day !== null) {
            return $day->timezone($timezone)->startOfDay();
        }

        return CarbonImmutable::now($timezone)->subHours(12)->startOfDay();
    }

    private function timezone(User $user): string
    {
        $timezone = CommsPreference::query()->whereBelongsTo($user)->value('timezone');

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('agenda.timezone', config('app.timezone'));
    }

    /**
     * @return Collection<string, Collection<int, CommsConversationLink>>
     */
    private function topics(User $user, CarbonImmutable $day): Collection
    {
        $links = CommsConversationLink::query()
            ->whereBelongsTo($user)
            ->whereHas('message', function ($query) use ($day): void {
                $query->whereBetween('occurred_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
            })
            ->with(['message.author', 'intent'])
            ->get()
            ->filter(fn (CommsConversationLink $link): bool => $link->message instanceof CommsMessage
                && $link->intent instanceof CommsIntent
                && is_string($link->intent->topic_subject)
                && $link->intent->topic_subject !== '')
            ->sortBy(fn (CommsConversationLink $link): array => [
                $link->message->occurred_at->getTimestamp(),
                $link->message->id,
                $link->id,
            ])
            ->groupBy(fn (CommsConversationLink $link): string => (string) $link->intent->topic_subject);

        return $links->sortKeys();
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $links
     */
    private function read(User $user, CarbonImmutable $day, string $topic, Collection $links): int
    {
        $messages = $links->map(fn (CommsConversationLink $link): CommsMessage => $link->message)->unique('id')->values();
        $answer = $this->router->ask(
            $user,
            'reconcile',
            $this->input($user, $day, $topic, $links),
            Schemas::reconcile(),
            $messages->map(fn (CommsMessage $message): int => $message->event_id)->unique()->values()->all(),
        );

        if ($answer->kind !== ModelAnswerKind::Accepted || ! is_array($answer->output)) {
            return 0;
        }

        $changes = $answer->output['changes'] ?? null;

        if (! is_array($changes)) {
            return 0;
        }

        $stored = 0;

        foreach ($changes as $change) {
            if (! is_array($change) || ! is_string($change['op'] ?? null) || $change['op'] === '') {
                continue;
            }

            CommsReconcileChange::query()->create([
                'user_id' => $user->id,
                'run_date' => $day->toDateString(),
                'op' => $change['op'],
                'payload' => $change,
                'quote' => is_string($change['quote'] ?? null) ? $change['quote'] : null,
                'why' => is_string($change['why'] ?? null) ? $change['why'] : null,
                'disposition' => 'proposed',
                'decision_event_id' => $answer->event->id,
            ]);
            $stored++;
        }

        return $stored;
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $links
     */
    private function input(User $user, CarbonImmutable $day, string $topic, Collection $links): string
    {
        return implode("\n\n", [
            "DOSSIER\n".$this->dossier($user, $topic),
            "MESSAGES\n".$this->messages($links),
            "INTENTS\n".$this->intents($user, $day, $topic),
        ]);
    }

    private function dossier(User $user, string $topic): string
    {
        $document = CommsTopicSearch::query()
            ->whereBelongsTo($user)
            ->where('topic_subject', $topic)
            ->orderBy('id')
            ->value('document');
        $text = trim(str_replace(["\r", "\n"], ' ', (string) $document));

        return $text === '' ? '(none)' : $text;
    }

    /**
     * @param  Collection<int, CommsConversationLink>  $links
     */
    private function messages(Collection $links): string
    {
        if ($links->isEmpty()) {
            return '(none)';
        }

        return $links->map(function (CommsConversationLink $link): string {
            $message = $link->message;
            $alternative = is_array($link->alternative) ? (string) ($link->alternative[0] ?? '') : '';
            $channel = $message->source->value.($message->channel_ref === null || $message->channel_ref === '' ? '' : ':'.$message->channel_ref);
            $attributes = ' id="m'.$message->id.'"'
                .' author="person:'.$message->author_person_id.'"'
                .' at="'.$message->occurred_at->toIso8601String().'"'
                .' channel="'.$channel.'"'
                .' intent="'.$link->intent_id.'"'
                .' link="'.($link->uncertain ? 'uncertain' : 'certain').'"';

            if ($alternative !== '') {
                $attributes .= ' alternative="'.$alternative.'"';
            }

            return '<source'.$attributes.'>'.$message->text.'</source>';
        })->implode("\n");
    }

    private function intents(User $user, CarbonImmutable $day, string $topic): string
    {
        $intents = CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('topic_subject', $topic)
            ->where(function ($query) use ($day): void {
                $query->where('state', 'open')
                    ->orWhereBetween('settled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
            })
            ->orderBy('id')
            ->get();

        if ($intents->isEmpty()) {
            return '(none)';
        }

        return $intents->map(fn (CommsIntent $intent): string => 'id:'.$intent->id
            .' | what:'.trim(str_replace(["\r", "\n"], ' ', (string) $intent->what))
            .' | state:'.$intent->state
            .' | settled:'.($intent->settled_at?->toIso8601String() ?? ''))->implode("\n");
    }
}
