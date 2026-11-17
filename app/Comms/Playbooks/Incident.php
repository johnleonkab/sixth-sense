<?php

namespace App\Comms\Playbooks;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Notify\Notifier;
use App\Comms\Watchers\BusinessCalendar;
use App\Knowledge\KnowledgeSource;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsWatcher;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;

/**
 * Cases 21, 42 and 45. An incident is told now, with a holding reply and
 * the recent ticket or merge. A pre-release bug names the release. A revert
 * tells whoever was told it was live. The event text is never copied.
 */
class Incident
{
    public function __construct(private Container $container, private BusinessCalendar $calendar) {}

    public function source(CommsEvent $event): void
    {
        $user = $event->user;
        $data = $event->normalized ?? [];

        if ($user === null) {
            return;
        }

        match ((string) ($data['kind'] ?? '')) {
            'incident' => $this->reported($user, $event, $data, (string) ($data['channel'] ?? '')),
            'pre_release' => $this->release($user, $event, $data),
            'revert' => $this->revert($user, $event, $data),
            default => null,
        };
    }

    public function message(User $user, CommsMessage $message): void
    {
        $event = CommsEvent::query()->find($message->event_id);

        if (! $event instanceof CommsEvent) {
            return;
        }

        $this->reported($user, $event, [
            'subject' => 'incidencia',
            'reporter' => $message->author?->display_name,
            'urgent' => ($message->flags['urgent'] ?? false) === true,
            'production' => ($message->flags['production'] ?? false) === true,
        ], (string) $message->channel_ref);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function reported(User $user, CommsEvent $event, array $data, string $channel): void
    {
        if (! $this->triggered($data, $channel)) {
            return;
        }

        $subject = $this->clean((string) ($data['subject'] ?? ''), 'incidencia');
        $marker = 'incident:'.$subject;

        if ($this->already($user, $marker)) {
            return;
        }

        $reporter = $this->clean((string) ($data['reporter'] ?? ''), 'quien avisó');
        $ticket = $this->ticket($user, $subject);
        $merge = $this->merge($user);
        $lines = [];

        if ($ticket instanceof KnowledgeDocument) {
            $lines[] = 'Hay un ticket: '.$this->ref($ticket).'.';
        } else {
            $lines[] = 'No hay ticket reciente.';
        }

        if ($merge instanceof KnowledgeDocument) {
            $lines[] = 'Merge reciente: '.$this->ref($merge).'.';
        }

        $lines[] = 'Estamos en ello. Te aviso cuando esté.';
        $intent = $this->intent($user, $event, $marker, 'Incidencia: '.$subject);
        $this->propose($user, $event, $intent, ActionClass::SendSlack, implode(' ', $lines), $marker);

        if (! $ticket instanceof KnowledgeDocument) {
            $this->propose($user, $event, $intent, ActionClass::JiraUpdate, 'Abrir un ticket de incidencia.', $marker.':open');
        }

        $this->watch($user, $event, $intent, $ticket instanceof KnowledgeDocument ? $this->ref($ticket) : $marker, $reporter);
        $this->tell($user, $event, 'n:'.$marker);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function release(User $user, CommsEvent $event, array $data): void
    {
        $version = $this->clean((string) ($data['release'] ?? ''), '');

        if ($version === '') {
            return;
        }

        $subject = $this->clean((string) ($data['subject'] ?? ''), '');
        $marker = 'release:'.$version;
        $ticket = $subject !== '' ? $this->ticket($user, $subject) : null;
        $known = $ticket instanceof KnowledgeDocument ? 'Ya está en '.$this->ref($ticket).'. ' : '';
        $intent = $this->intent($user, $event, $marker, 'Fallo antes de '.$version);

        $this->propose(
            $user,
            $event,
            $intent,
            ActionClass::SendSlack,
            $known.'El fallo está en la versión '.$version.'. ¿Lo arreglo antes, lo saco como conocido o lo quito de la versión?',
            $marker,
        );
        $this->tell($user, $event, 'n:'.$marker);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function revert(User $user, CommsEvent $event, array $data): void
    {
        $what = $this->clean((string) ($data['what'] ?? ''), 'el cambio');
        $told = array_values(array_filter(
            $data['told'] ?? [],
            fn (mixed $name): bool => is_string($name) && $this->clean($name, '') !== '',
        ));
        $tickets = array_values(array_filter(
            $data['tickets'] ?? [],
            fn (mixed $ref): bool => is_string($ref) && $this->clean($ref, '') !== '',
        ));
        $who = $told === [] ? 'quien se enteró' : implode(' y ', $told);
        $marker = 'revert:'.$what;
        $intent = $this->intent($user, $event, $marker, 'Revertir '.$what);

        $this->propose(
            $user,
            $event,
            $intent,
            ActionClass::SendSlack,
            'Se revirtió «'.$what.'». Hay que decírselo a '.$who.'. ¿Se lo digo?',
            $marker,
        );

        if ($tickets !== []) {
            $this->propose(
                $user,
                $event,
                $intent,
                ActionClass::JiraUpdate,
                '¿Reabro '.implode(' y ', $tickets).'?',
                $marker.':reopen',
            );
        }

        $this->tell($user, $event, 'n:'.$marker);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function triggered(array $data, string $channel): bool
    {
        $urgent = ($data['urgent'] ?? false) === true;
        $production = ($data['production'] ?? false) === true;

        return ($urgent && $production) || str_contains(mb_strtolower($channel), 'incident');
    }

    private function ticket(User $user, string $subject): ?KnowledgeDocument
    {
        $needle = mb_strtolower($subject);

        if (mb_strlen($needle) < 4) {
            return null;
        }

        return $this->recent($user, KnowledgeSource::Jira, CarbonImmutable::now()->subDays(30))
            ->first(fn (KnowledgeDocument $document): bool => str_contains($this->haystack($document), $needle));
    }

    private function merge(User $user): ?KnowledgeDocument
    {
        return $this->recent($user, KnowledgeSource::Archive, CarbonImmutable::now()->subHours(72))
            ->first(fn (KnowledgeDocument $document): bool => str_contains($this->haystack($document), 'merge'));
    }

    /**
     * @return Collection<int, KnowledgeDocument>
     */
    private function recent(User $user, KnowledgeSource $source, CarbonImmutable $since): Collection
    {
        return KnowledgeDocument::query()
            ->whereBelongsTo($user)
            ->where('source', $source)
            ->where('source_updated_at', '>=', $since)
            ->orderByDesc('source_updated_at')
            ->orderByDesc('id')
            ->get();
    }

    private function haystack(KnowledgeDocument $document): string
    {
        return mb_strtolower($document->title.' '.($document->terms ?? '').' '.($document->summary ?? ''));
    }

    private function ref(KnowledgeDocument $document): string
    {
        if (str_contains($document->ref, ':')) {
            return $document->ref;
        }

        return $document->source === KnowledgeSource::Jira ? 'jira:'.$document->ref : $document->ref;
    }

    private function intent(User $user, CommsEvent $event, string $key, string $what): CommsIntent
    {
        return CommsIntent::query()->firstOrCreate(
            ['user_id' => $user->id, 'key' => $key],
            [
                'what' => $what,
                'state' => 'open',
                'importance' => 40,
                'flags' => ['kind' => 'request', 'urgent' => true, 'production' => true],
                'opened_event_id' => $event->id,
            ],
        );
    }

    private function watch(User $user, CommsEvent $event, CommsIntent $intent, string $ref, string $reporter): void
    {
        CommsWatcher::query()->firstOrCreate(
            ['user_id' => $user->id, 'key' => 'incident:'.$intent->id],
            [
                'intent_id' => $intent->id,
                'condition_type' => 'delivery',
                'condition' => ['ref' => $ref, 'reporter' => $reporter],
                'deadline_at' => $this->calendar->addDays($user, CarbonImmutable::parse($event->occurred_at), 5),
                'attempt' => 0,
                'on_fire' => 'incident',
                'state' => 'active',
            ],
        );
    }

    private function clean(string $text, string $fallback): string
    {
        $text = trim($text);

        if ($text === '' || preg_match('/password|ignore previous/i', $text) === 1) {
            return $fallback;
        }

        return $text;
    }

    private function already(User $user, string $marker): bool
    {
        return CommsProposal::query()->whereBelongsTo($user)->where('payload->marker', $marker)->exists();
    }

    private function propose(
        User $user,
        CommsEvent $event,
        CommsIntent $intent,
        ActionClass $class,
        string $text,
        string $marker,
    ): void {
        if ($this->already($user, $marker)) {
            return;
        }

        $this->container->make(Gate::class)->submit(
            user: $user,
            class: $class,
            target: ['ref' => 'self', 'from_user' => true, 'channel' => 'C1'],
            payload: [
                'text' => $text,
                'to' => 'C1',
                'marker' => $marker,
            ],
            intent: $intent,
            sourceEventIds: [$event->id],
            statesProductFacts: false,
            commitsDate: false,
            notify: false,
        );
    }

    private function tell(User $user, CommsEvent $event, string $key): void
    {
        $this->container->make(Notifier::class)->store($user, 'signal', 'now', null, null, $key, [$event->id]);
    }
}
