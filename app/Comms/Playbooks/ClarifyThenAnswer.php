<?php

namespace App\Comms\Playbooks;

use App\Comms\Calendar\SlotFinder;
use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Watchers\BusinessCalendar;
use App\Knowledge\KnowledgeSource;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsWatcher;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cases 2, 34 and 47. A vague call is asked, read, then offered as a meeting
 * or a direct answer. A feature is checked before anyone is answered, and the
 * reply never carries a date. An estimate is a range until the user commits.
 */
class ClarifyThenAnswer
{
    public function __construct(
        private RouteRules $rules,
        private Gate $gate,
        private SlotFinder $slots,
        private BusinessCalendar $calendar,
    ) {}

    public function run(User $user, CommsIntent $intent, CommsMessage $message): void
    {
        $run = CommsPlaybookRun::query()->whereBelongsTo($user)->where('key', 'clarify:'.$intent->key)->first();
        $shape = is_string($run?->context['shape'] ?? null) ? $run->context['shape'] : $this->rules->shape($message->text);

        if ($shape === null) {
            return;
        }

        if (! $run instanceof CommsPlaybookRun) {
            $this->start($user, $intent, $message, $shape);

            return;
        }

        if ($message->event_id === $run->trigger_event_id || $shape !== 'call') {
            return;
        }

        if ($run->step === 'subject') {
            $this->doubts($user, $intent, $message, $run);

            return;
        }

        if ($run->step === 'doubts') {
            $this->options($user, $intent, $message, $run);
        }
    }

    public function committed(CommsEvent $event): void
    {
        $user = $event->user;
        $date = $this->date($event);

        if ($user === null || $date === null) {
            return;
        }

        $run = CommsPlaybookRun::query()
            ->whereBelongsTo($user)
            ->where('playbook', 'clarify_then_answer')
            ->where('step', 'range')
            ->orderByDesc('id')
            ->first();
        $intent = $run?->intent;

        if (! $run instanceof CommsPlaybookRun || ! $intent instanceof CommsIntent) {
            return;
        }

        $intent->deadline = $date;
        $intent->deadline_text = 'Fecha comprometida';
        $intent->save();

        CommsWatcher::query()->firstOrCreate(
            ['user_id' => $user->id, 'key' => 'clarify:'.$intent->id.':date'],
            [
                'intent_id' => $intent->id,
                'condition_type' => 'delivery',
                'condition' => ['ref' => $intent->key],
                'deadline_at' => $date,
                'attempt' => 0,
                'on_fire' => 'clarify_then_answer',
                'state' => 'active',
            ],
        );

        $run->step = 'committed';
        $run->state = 'done';
        $run->save();
    }

    private function start(User $user, CommsIntent $intent, CommsMessage $message, string $shape): void
    {
        $run = CommsPlaybookRun::query()->create([
            'user_id' => $user->id,
            'key' => 'clarify:'.$intent->key,
            'playbook' => 'clarify_then_answer',
            'intent_id' => $intent->id,
            'state' => 'waiting',
            'step' => 'start',
            'context' => ['shape' => $shape],
            'trigger_event_id' => $message->event_id,
        ]);

        if ($shape === 'call') {
            $this->propose($user, $intent, $run, ActionClass::SendSlack, '¿De qué se trata la llamada?', 'clarify:'.$intent->id.':subject', [$message->event_id]);
            $run->step = 'subject';
            $run->save();

            return;
        }

        if ($shape === 'feature') {
            $this->feature($user, $intent, $message, $run);

            return;
        }

        $this->propose(
            $user,
            $intent,
            $run,
            ActionClass::SendSlack,
            $this->range($user, $intent),
            'clarify:'.$intent->id.':range',
            [$message->event_id],
        );
        $run->step = 'range';
        $run->save();
    }

    private function doubts(User $user, CommsIntent $intent, CommsMessage $message, CommsPlaybookRun $run): void
    {
        $context = $run->context ?? [];
        $context['subject'] = $this->safe($message->text, 'el tema');
        $run->context = $context;
        $run->step = 'doubts';
        $run->save();

        $this->propose($user, $intent, $run, ActionClass::SendSlack, '¿Qué dudas tienes?', 'clarify:'.$intent->id.':doubts', [$message->event_id]);
    }

    private function options(User $user, CommsIntent $intent, CommsMessage $message, CommsPlaybookRun $run): void
    {
        $doubts = $this->safe($message->text, 'las dudas');
        $context = $run->context ?? [];
        $context['doubts'] = $doubts;
        $run->context = $context;
        $run->step = 'options';
        $run->state = 'done';
        $run->save();

        $from = CarbonImmutable::now();
        $found = $this->slots->find($user, $from, $this->calendar->addDays($user, $from, 2), 30, false);
        $slot = $found[0] ?? null;

        $this->propose(
            $user,
            $intent,
            $run,
            ActionClass::CalendarCreate,
            'Agenda: '.$doubts,
            'clarify:'.$intent->id.':meeting',
            [$message->event_id],
            $slot,
        );
        $this->propose(
            $user,
            $intent,
            $run,
            ActionClass::SendSlack,
            'Te lo contesto por aquí, sin quedar.',
            'clarify:'.$intent->id.':answer',
            [$message->event_id],
        );

        CommsWatcher::query()->firstOrCreate(
            ['user_id' => $user->id, 'key' => 'clarify:'.$intent->id.':transcript'],
            [
                'intent_id' => $intent->id,
                'condition_type' => 'delivery',
                'condition' => ['ref' => $intent->key],
                'deadline_at' => $this->calendar->addDays($user, $from, 7),
                'attempt' => 0,
                'on_fire' => 'transcript',
                'state' => 'active',
            ],
        );
    }

    private function feature(User $user, CommsIntent $intent, CommsMessage $message, CommsPlaybookRun $run): void
    {
        $documents = $this->documents($user, $intent);
        $lines = [];

        foreach ([
            [KnowledgeSource::Archive, 'el producto'],
            [KnowledgeSource::Jira, 'el backlog'],
            [KnowledgeSource::Notion, 'el roadmap'],
        ] as [$source, $place]) {
            $hit = $documents->first(fn (KnowledgeDocument $document): bool => $document->source === $source);

            if ($hit instanceof KnowledgeDocument) {
                $lines[] = 'Está en '.$place.': '.$this->undated($hit->title).'.';
            }
        }

        if ($lines === []) {
            $who = $intent->participants()->with('person')->get()
                ->first(fn ($participant): bool => $participant->role === 'requester')
                ?->person
                ?->display_name ?? 'quien lo pidió';
            $this->propose($user, $intent, $run, ActionClass::SendSlack, 'Lo anoto. No hay fecha.', 'clarify:'.$intent->id.':note', [$message->event_id]);
            $this->propose(
                $user,
                $intent,
                $run,
                ActionClass::JiraUpdate,
                'Quién lo pide: '.$who.'. Qué: '.$this->undated($intent->what).'.',
                'clarify:'.$intent->id.':backlog',
                [$message->event_id],
            );
        } else {
            $this->propose($user, $intent, $run, ActionClass::SendSlack, implode(' ', $lines), 'clarify:'.$intent->id.':standing', [$message->event_id]);
            $backlog = $documents->first(fn (KnowledgeDocument $document): bool => $document->source === KnowledgeSource::Jira);

            if ($backlog instanceof KnowledgeDocument) {
                $ref = str_starts_with($backlog->ref, 'jira:') ? $backlog->ref : 'jira:'.$backlog->ref;
                $this->propose($user, $intent, $run, ActionClass::JiraComment, '¿Lo enlazo a '.$ref.'?', 'clarify:'.$intent->id.':link', [$message->event_id]);
            }
        }

        $run->step = 'replied';
        $run->state = 'done';
        $run->save();
    }

    private function range(User $user, CommsIntent $intent): string
    {
        $dependency = $this->documents($user, $intent)
            ->first(fn (KnowledgeDocument $document): bool => $document->source === KnowledgeSource::Jira);

        $depends = $dependency instanceof KnowledgeDocument
            ? $this->undated($dependency->title)
            : 'lo que quede abierto';

        return 'Entre 3 y 5 días. Depende de '.$depends.'.';
    }

    /**
     * @return Collection<int, KnowledgeDocument>
     */
    private function documents(User $user, CommsIntent $intent): Collection
    {
        $needles = $this->needles($intent->what);

        if ($needles === []) {
            return collect();
        }

        return KnowledgeDocument::query()
            ->whereBelongsTo($user)
            ->get()
            ->filter(function (KnowledgeDocument $document) use ($needles): bool {
                $haystack = mb_strtolower($document->title.' '.($document->terms ?? '').' '.($document->summary ?? ''));

                foreach ($needles as $needle) {
                    if (str_contains($haystack, $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function needles(string $what): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($what)) ?: [];

        return array_values(array_filter(
            $words,
            fn (string $word): bool => mb_strlen($word) >= 6,
        ));
    }

    private function safe(string $text, string $fallback): string
    {
        $clean = trim($text);

        if ($clean === '' || preg_match('/password|ignore previous/i', $clean) === 1) {
            return $fallback;
        }

        return mb_substr($this->undated($clean), 0, 160);
    }

    private function undated(string $text): string
    {
        $clean = preg_replace('/\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/u', '', $text) ?? $text;

        return trim((string) preg_replace('/\s+/', ' ', $clean));
    }

    private function date(CommsEvent $event): ?CarbonImmutable
    {
        $explicit = $event->normalized['date'] ?? null;

        if (is_string($explicit) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $explicit) === 1) {
            return CarbonImmutable::parse($explicit)->startOfDay();
        }

        $text = (string) ($event->normalized['text'] ?? '');

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $found) === 1) {
            return CarbonImmutable::parse($found[1])->startOfDay();
        }

        return null;
    }

    /**
     * @param  list<int>  $sourceEventIds
     * @param  array{starts_at: string, ends_at: string}|null  $slot
     */
    private function propose(
        User $user,
        CommsIntent $intent,
        CommsPlaybookRun $run,
        ActionClass $class,
        string $text,
        string $marker,
        array $sourceEventIds,
        ?array $slot = null,
    ): void {
        if (CommsProposal::query()->whereBelongsTo($user)->where('payload->marker', $marker)->exists()) {
            return;
        }

        $text = $this->undated($text);
        $payload = [
            'text' => $text,
            'to' => 'C1',
            'marker' => $marker,
        ];

        if ($class === ActionClass::CalendarCreate) {
            $payload['title'] = 'Llamada';
            $payload['starts_at'] = $slot['starts_at'] ?? null;
            $payload['ends_at'] = $slot['ends_at'] ?? null;
        }

        if ($class === ActionClass::JiraUpdate) {
            $payload['title'] = $this->undated($intent->what);
        }

        $proposal = $this->gate->submit(
            user: $user,
            class: $class,
            target: ['ref' => 'self', 'from_user' => true, 'channel' => 'C1'],
            payload: $payload,
            intent: $intent,
            sourceEventIds: $sourceEventIds,
            statesProductFacts: false,
            commitsDate: false,
            notify: false,
        );
        $proposal->run_id = $run->id;
        $proposal->save();
    }
}
