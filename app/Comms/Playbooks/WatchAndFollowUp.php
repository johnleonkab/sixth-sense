<?php

namespace App\Comms\Playbooks;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\People\Relation;
use App\Comms\Watchers\BusinessCalendar;
use App\Models\Comms\CommsCalendarEvent;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsWatcher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * Cases 38 and 39. A quiet ticket is checked for a review, a blocker or
 * an assignee who is away before anyone is pinged. A blocker that is the
 * user becomes their intent. The event text is never copied.
 */
class WatchAndFollowUp
{
    public function __construct(private BusinessCalendar $calendar, private Container $container) {}

    public function source(CommsEvent $event): void
    {
        $user = $event->user;
        $data = $event->normalized ?? [];

        if ($user === null || ($data['kind'] ?? '') !== 'ticket') {
            return;
        }

        $ref = trim((string) ($data['ref'] ?? ''));
        $status = (string) ($data['status'] ?? '');

        if ($ref === '') {
            return;
        }

        if ($status === 'blocked' || (is_string($data['blocker'] ?? null) && trim($data['blocker']) !== '')) {
            $this->blocked($user, $event, $ref);

            return;
        }

        if (! in_array($status, ['in_progress', 'review'], true) || ! $this->quiet($user, $event)) {
            return;
        }

        $this->stuck($user, $event, $ref);
    }

    private function quiet(User $user, CommsEvent $event): bool
    {
        $data = $event->normalized ?? [];
        $changed = CarbonImmutable::parse((string) ($data['changed_at'] ?? $event->occurred_at));
        $days = ($data['in_cycle'] ?? false) === true ? 2 : 3;
        $cutoff = $this->calendar->subDays($user, CarbonImmutable::parse($event->occurred_at), $days);

        return $changed->startOfDay()->lte($cutoff);
    }

    private function stuck(User $user, CommsEvent $event, string $ref): void
    {
        $data = $event->normalized ?? [];
        $marker = 'stuck:'.$ref;

        if (CommsProposal::query()->whereBelongsTo($user)->where('payload->marker', $marker)->exists()) {
            return;
        }

        $assignee = trim((string) ($data['assignee'] ?? ''));
        $who = $assignee !== '' ? $assignee : 'quien lo lleva';

        if (($data['waiting_review'] ?? false) === true) {
            $reason = 'Hay una revisión esperando.';
        } elseif (is_string($data['blocker'] ?? null) && trim($data['blocker']) !== '') {
            $reason = 'Lo bloquea '.trim($data['blocker']).'.';
        } elseif ($this->away($user, $event, $assignee)) {
            $reason = $who.' no está.';
        } else {
            $reason = '¿Pregunto a '.$who.'?';
        }

        $this->propose($user, null, $ref.' lleva parado. '.$reason, $marker, [$event->id]);
    }

    private function blocked(User $user, CommsEvent $event, string $ref): void
    {
        $data = $event->normalized ?? [];
        $blocker = trim((string) ($data['blocker'] ?? ''));
        $self = CommsPerson::query()->whereBelongsTo($user)->where('relation', Relation::Self)->first();
        $mine = $self instanceof CommsPerson && ($blocker === 'self' || mb_strtolower($blocker) === mb_strtolower($self->display_name));

        $intent = CommsIntent::query()->firstOrCreate(
            ['user_id' => $user->id, 'key' => 'block:'.$ref],
            [
                'what' => 'Desbloquear '.$ref,
                'state' => 'open',
                'importance' => $mine ? 60 : 40,
                'flags' => ['kind' => 'request'],
                'opened_event_id' => $event->id,
            ],
        );

        if ($mine) {
            $intent->importance = max(60, (int) $intent->importance);
            $intent->save();
            $this->participant($user, $intent, $self, 'responder');

            return;
        }

        $name = $blocker !== '' ? $blocker : 'quien lo bloquea';
        $person = $this->person($user, $name);
        $this->participant($user, $intent, $person, 'responder');

        if ($self instanceof CommsPerson) {
            $this->participant($user, $intent, $self, 'requester');
        }

        $marker = 'blocked:'.$ref;

        if (! CommsProposal::query()->where('intent_id', $intent->id)->where('payload->marker', $marker)->exists()) {
            $this->propose($user, $intent, $ref.' está bloqueado. ¿Se lo digo a '.$name.'?', $marker, [$event->id]);
        }

        CommsWatcher::query()->firstOrCreate(
            ['user_id' => $user->id, 'key' => 'block:'.$ref.':watch'],
            [
                'intent_id' => $intent->id,
                'condition_type' => 'delivery',
                'condition' => ['ref' => $ref],
                'deadline_at' => $this->calendar->addDays($user, CarbonImmutable::parse($event->occurred_at), 2),
                'attempt' => 0,
                'on_fire' => 'watch_and_follow_up',
                'state' => 'active',
            ],
        );
    }

    private function away(User $user, CommsEvent $event, string $assignee): bool
    {
        if (($event->normalized['assignee_away'] ?? false) === true) {
            return true;
        }

        if ($assignee === '') {
            return false;
        }

        $now = CarbonImmutable::parse($event->occurred_at);
        $needle = mb_strtolower($assignee);

        return CommsCalendarEvent::query()
            ->whereBelongsTo($user)
            ->where('status', 'confirmed')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->get()
            ->contains(function (CommsCalendarEvent $meeting) use ($needle): bool {
                $haystack = mb_strtolower($meeting->title.' '.implode(' ', $meeting->attendees ?? []));

                return str_contains($haystack, $needle)
                    && ($meeting->kind === 'out_of_office' || str_contains(mb_strtolower($meeting->title), 'fuera'));
            });
    }

    /**
     * @param  list<int>  $sourceEventIds
     */
    private function propose(User $user, ?CommsIntent $intent, string $text, string $marker, array $sourceEventIds): void
    {
        $this->container->make(Gate::class)->submit(
            user: $user,
            class: ActionClass::SendSlack,
            target: ['ref' => 'self', 'from_user' => true, 'channel' => 'C1'],
            payload: [
                'text' => $text,
                'to' => 'C1',
                'marker' => $marker,
            ],
            intent: $intent,
            sourceEventIds: $sourceEventIds,
            statesProductFacts: false,
            notify: false,
        );
    }

    private function participant(User $user, CommsIntent $intent, CommsPerson $person, string $role): void
    {
        CommsIntentParticipant::query()->firstOrCreate([
            'user_id' => $user->id,
            'intent_id' => $intent->id,
            'person_id' => $person->id,
            'role' => $role,
        ]);
    }

    private function person(User $user, string $name): CommsPerson
    {
        $existing = CommsPerson::query()->whereBelongsTo($user)->where('display_name', $name)->first();

        if ($existing instanceof CommsPerson) {
            return $existing;
        }

        return CommsPerson::query()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'relation' => Relation::Collaborator,
            'importance_weight' => Relation::Collaborator->weight(),
        ]);
    }
}
