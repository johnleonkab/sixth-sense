<?php

namespace App\Comms\Playbooks;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Intents\IntentLinkKind;
use App\Comms\Notify\Notifier;
use App\Comms\Watchers\BusinessCalendar;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentLink;
use App\Models\Comms\CommsPerson;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * Cases 16, 24, 29, 50 and 59. A downstream ask waits on an upstream one.
 * A meeting that was the deadline moves with it. Someone leaving hands
 * their open work to someone else. The event text is never copied.
 */
class DependencyRisk
{
    public function __construct(private BusinessCalendar $calendar, private Container $container) {}

    public function applied(User $user, CommsEvent $applied): void
    {
        foreach ($applied->normalized['results'] ?? [] as $result) {
            if (! is_array($result) || ($result['status'] ?? '') !== 'accepted' || ! is_string($result['intent_key'] ?? null)) {
                continue;
            }

            $intent = CommsIntent::query()->whereBelongsTo($user)->where('key', $result['intent_key'])->first();

            if ($intent === null) {
                continue;
            }

            foreach ($result['depends_on'] ?? [] as $ref) {
                if (! is_string($ref) || $ref === '') {
                    continue;
                }

                $upstream = $this->intent($user, $ref);

                if ($upstream === null || $upstream->id === $intent->id) {
                    continue;
                }

                CommsIntentLink::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'from_intent_id' => $intent->id,
                    'to_intent_id' => $upstream->id,
                    'kind' => IntentLinkKind::DependsOn,
                ]);
                $this->warn($user, $intent, $upstream);
            }

            if (($result['op'] ?? '') === 'settle') {
                $this->arrived($user, $intent, $applied);
            }
        }
    }

    public function source(CommsEvent $event): void
    {
        $user = $event->user;
        $data = $event->normalized ?? [];

        if ($user === null) {
            return;
        }

        if (($data['kind'] ?? '') === 'left') {
            $this->leaving($user, $event);

            return;
        }

        if (is_string($data['title'] ?? null) && (isset($data['starts_at']) || ($data['status'] ?? '') === 'cancelled')) {
            $this->meeting($user, $event);
        }
    }

    private function warn(User $user, CommsIntent $downstream, CommsIntent $upstream): void
    {
        if ($upstream->state !== 'open' || $upstream->deadline === null || $downstream->deadline === null) {
            return;
        }

        $marker = 'dependency:'.$upstream->id;

        if (CommsProposal::query()->where('intent_id', $downstream->id)->where('payload->marker', $marker)->exists()) {
            return;
        }

        $limit = $this->calendar->subDays($user, CarbonImmutable::parse($downstream->deadline)->startOfDay(), 1);

        if (CarbonImmutable::parse($upstream->deadline)->startOfDay()->lte($limit)) {
            return;
        }

        $this->container->make(Gate::class)->submit(
            user: $user,
            class: ActionClass::SendSlack,
            target: ['ref' => 'self', 'from_user' => true, 'channel' => 'C1'],
            payload: [
                'text' => 'Lo de antes no llega a tiempo. ¿Aviso a quien lo espera?',
                'to' => 'C1',
                'marker' => $marker,
            ],
            intent: $downstream,
            statesProductFacts: false,
            notify: false,
        );
    }

    private function arrived(User $user, CommsIntent $upstream, CommsEvent $event): void
    {
        $links = CommsIntentLink::query()
            ->whereBelongsTo($user)
            ->where('to_intent_id', $upstream->id)
            ->where('kind', IntentLinkKind::DependsOn)
            ->get();

        foreach ($links as $link) {
            $this->container->make(Notifier::class)->store(
                $user,
                'signal',
                'now',
                'intent',
                $link->from_intent_id,
                'n:dep:'.$upstream->id.':'.$link->from_intent_id,
                [$event->id],
            );
        }
    }

    private function meeting(User $user, CommsEvent $event): void
    {
        $data = $event->normalized ?? [];
        $title = trim((string) ($data['title'] ?? ''));

        if (mb_strlen($title) < 3) {
            return;
        }

        $waiting = CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('state', 'open')
            ->whereNotNull('deadline_text')
            ->get()
            ->filter(fn (CommsIntent $intent): bool => str_contains(mb_strtolower((string) $intent->deadline_text), mb_strtolower($title)));

        if ($waiting->isEmpty()) {
            return;
        }

        if (($data['status'] ?? '') === 'cancelled' || ! isset($data['starts_at'])) {
            CommsQuestion::query()->updateOrCreate(
                ['user_id' => $user->id, 'key' => 'q:meet:'.$event->id],
                [
                    'intent_id' => $waiting->first()?->id,
                    'text' => $title.' se canceló. ¿Cómo lo cerramos?',
                    'context' => ['draft' => ''],
                    'options' => [],
                ],
            );

            return;
        }

        $date = CarbonImmutable::parse((string) $data['starts_at'])->toDateString();

        foreach ($waiting as $intent) {
            $intent->deadline = $date;
            $intent->save();
        }
    }

    private function leaving(User $user, CommsEvent $event): void
    {
        $name = trim((string) ($event->normalized['person'] ?? ''));
        $person = $name === ''
            ? null
            : CommsPerson::query()->whereBelongsTo($user)->where('display_name', $name)->first();

        if ($person === null) {
            return;
        }

        $intents = CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('state', 'open')
            ->whereHas('participants', fn ($query) => $query->where('person_id', $person->id)->where('role', 'responder'))
            ->get();

        foreach ($intents as $intent) {
            $marker = 'leaving:'.$intent->id;

            if (CommsProposal::query()->where('intent_id', $intent->id)->where('payload->marker', $marker)->exists()) {
                continue;
            }

            $this->container->make(Gate::class)->submit(
                user: $user,
                class: ActionClass::SendSlack,
                target: ['ref' => 'self', 'from_user' => true, 'channel' => 'C1'],
                payload: [
                    'text' => $name.' deja «'.$intent->what.'» abierto. ¿A quién se lo paso?',
                    'to' => 'C1',
                    'marker' => $marker,
                ],
                intent: $intent,
                sourceEventIds: [$event->id],
                statesProductFacts: false,
                notify: false,
            );
        }
    }

    private function intent(User $user, string $ref): ?CommsIntent
    {
        $ref = str_starts_with($ref, 'intent:') ? substr($ref, 7) : $ref;

        if (ctype_digit($ref)) {
            return CommsIntent::query()->whereBelongsTo($user)->find((int) $ref);
        }

        return CommsIntent::query()->whereBelongsTo($user)->where('key', $ref)->first();
    }
}
