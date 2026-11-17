<?php

namespace App\Comms\Playbooks;

use App\Comms\Gate\ActionClass;
use App\Comms\Gate\Gate;
use App\Comms\Models\ModelAnswerKind;
use App\Comms\Models\ModelRouter;
use App\Comms\Notify\Notifier;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsOwnedArea;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use Illuminate\Contracts\Container\Container;

/**
 * Cases 17, 35, 36, 40 and 56. Both statements are shown with their refs.
 * The question goes to the area owner. A client promise is told now. A
 * difference with the spec takes no side. The event text is never copied.
 */
class Contradiction
{
    public function __construct(private Container $container) {}

    public function source(CommsEvent $event): void
    {
        $user = $event->user;
        $data = $event->normalized ?? [];

        if ($user === null) {
            return;
        }

        match ((string) ($data['kind'] ?? '')) {
            'contradiction' => $this->statements($user, $event),
            'client_promise' => $this->promise($user, $event),
            'priorities' => $this->priorities($user, $event),
            'spec_diff' => $this->diff($user, $event),
            'decision_reversed' => $this->reversed($user, $event),
            default => null,
        };
    }

    private function statements(User $user, CommsEvent $event): void
    {
        $left = $this->side($event->normalized['left'] ?? null);
        $right = $this->side($event->normalized['right'] ?? null);

        if ($left === null || $right === null) {
            return;
        }

        $marker = 'contra:'.$left['ref'].':'.$right['ref'];

        if ($this->already($user, $marker)) {
            return;
        }

        $question = $this->question(
            $user,
            $event,
            "Izquierda ({$left['ref']}): {$left['text']}\nDerecha ({$right['ref']}): {$right['text']}",
            '¿Cuál de las dos vale?',
        );
        $area = $this->area($user, (string) ($event->normalized['area'] ?? ''));
        $for = $area !== null ? 'Para '.$area->name.': ' : 'Para quien decida: ';

        $this->propose(
            $user,
            $event,
            ActionClass::SendSlack,
            $for.$left['ref'].' dice «'.$left['text'].'». '.$right['ref'].' dice «'.$right['text'].'». '.$question,
            $marker,
        );
        $this->tell($user, $event, 'digest', 'n:contra:'.$left['ref'].':'.$right['ref']);
    }

    private function promise(User $user, CommsEvent $event): void
    {
        $data = $event->normalized ?? [];
        $who = $this->clean((string) ($data['who'] ?? ''), 'Alguien');
        $what = $this->clean((string) ($data['what'] ?? ''), 'algo');
        $client = $this->clean((string) ($data['client'] ?? ''), 'un cliente');
        $by = $this->clean((string) ($data['by'] ?? ''), 'sin fecha');
        $roadmap = $this->clean((string) ($data['roadmap'] ?? ''), 'sin sitio en el roadmap');
        $marker = 'promise:'.$who.':'.$what.':'.$client;

        $this->propose(
            $user,
            $event,
            ActionClass::SendSlack,
            $who.' prometió «'.$what.'» a '.$client.' para '.$by.'. En el roadmap: '.$roadmap.'. ¿Lo confirmamos, lo corregimos o pedimos una llamada?',
            $marker,
        );
        $this->tell($user, $event, 'now', 'n:'.$marker);
    }

    private function priorities(User $user, CommsEvent $event): void
    {
        $asks = array_values(array_filter(
            $event->normalized['asks'] ?? [],
            fn (mixed $ask): bool => is_array($ask) && trim((string) ($ask['who'] ?? '')) !== '' && trim((string) ($ask['what'] ?? '')) !== '',
        ));

        if (count($asks) < 2) {
            return;
        }

        $lines = array_map(function (array $ask): string {
            $who = $this->clean((string) $ask['who'], 'Alguien');
            $what = $this->clean((string) $ask['what'], 'algo');
            $displaces = $this->clean((string) ($ask['displaces'] ?? ''), 'otra cosa');

            return $who.' pide «'.$what.'» y eso desplaza «'.$displaces.'»';
        }, $asks);

        $this->propose(
            $user,
            $event,
            ActionClass::SendSlack,
            implode('. ', $lines).'. ¿Los junto o se lo pregunto a quien decide?',
            'priorities:'.implode(':', array_map(fn (array $ask): string => (string) $ask['who'], $asks)),
        );
    }

    private function diff(User $user, CommsEvent $event): void
    {
        $data = $event->normalized ?? [];
        $change = $this->clean((string) ($data['change_ref'] ?? ''), '');
        $spec = $this->clean((string) ($data['spec_ref'] ?? ''), '');
        $diff = rtrim($this->clean((string) ($data['diff'] ?? ''), 'hay una diferencia'), '.');

        if ($change === '' || $spec === '') {
            return;
        }

        if ($this->already($user, 'diff:'.$change.':'.$spec)) {
            return;
        }

        $this->question(
            $user,
            $event,
            "Cambio ({$change}): {$diff}\nSpec ({$spec}): {$diff}",
            '¿Lo comentamos?',
        );

        $this->propose(
            $user,
            $event,
            ActionClass::JiraComment,
            $change.' y '.$spec.' no coinciden: '.$diff.'. No elijo cuál vale. ¿Lo comentamos?',
            'diff:'.$change.':'.$spec,
        );
    }

    private function reversed(User $user, CommsEvent $event): void
    {
        $data = $event->normalized ?? [];
        $original = $this->clean((string) ($data['original'] ?? ''), '');
        $who = $this->clean((string) ($data['who'] ?? ''), 'quien la tomó');

        if ($original === '') {
            return;
        }

        $depends = array_values(array_filter(
            $data['depends'] ?? [],
            fn (mixed $ref): bool => is_string($ref) && trim($ref) !== '',
        ));
        $on = $depends === [] ? 'nada que esté apuntado' : implode(' y ', $depends);

        $this->propose(
            $user,
            $event,
            ActionClass::SendSlack,
            'La decisión era «'.$original.'», la tomó '.$who.'. Depende de '.$on.'. ¿La retomamos con '.$who.'?',
            'reversed:'.$who.':'.$original,
        );
    }

    private function question(User $user, CommsEvent $event, string $input, string $fallback): string
    {
        $answer = $this->container->make(ModelRouter::class)->ask(
            user: $user,
            task: 'contradiction',
            input: $input,
            schema: [
                'type' => 'object',
                'required' => ['question'],
                'properties' => [
                    'question' => ['type' => 'string'],
                    'side' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            eventIds: [$event->id],
        );
        $question = $answer->kind === ModelAnswerKind::Accepted
            ? trim((string) ($answer->output['question'] ?? ''))
            : '';

        if ($question === '' || $this->unsafe($question) || $this->takesSide($question)) {
            return $fallback;
        }

        return $question;
    }

    private function area(User $user, string $ref): ?CommsOwnedArea
    {
        if ($ref === '') {
            return null;
        }

        return CommsOwnedArea::query()
            ->whereBelongsTo($user)
            ->where(function ($query) use ($ref): void {
                $query->where('ref', $ref)->orWhere('name', $ref);
            })
            ->first();
    }

    /**
     * @return array{text: string, ref: string}|null
     */
    private function side(mixed $side): ?array
    {
        if (! is_array($side)) {
            return null;
        }

        $text = $this->clean((string) ($side['text'] ?? ''), '');
        $ref = $this->clean((string) ($side['ref'] ?? ''), '');

        if ($text === '' || $ref === '') {
            return null;
        }

        return ['text' => $text, 'ref' => $ref];
    }

    private function clean(string $text, string $fallback): string
    {
        $text = trim($text);

        if ($text === '' || $this->unsafe($text)) {
            return $fallback;
        }

        return $text;
    }

    private function unsafe(string $text): bool
    {
        return preg_match('/password|ignore previous/i', $text) === 1;
    }

    private function takesSide(string $text): bool
    {
        return preg_match('/tiene razón|está mal|esta mal|es la correcta|manda/iu', $text) === 1;
    }

    private function already(User $user, string $marker): bool
    {
        return CommsProposal::query()->whereBelongsTo($user)->where('payload->marker', $marker)->exists();
    }

    private function propose(User $user, CommsEvent $event, ActionClass $class, string $text, string $marker): void
    {
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
            sourceEventIds: [$event->id],
            statesProductFacts: false,
            commitsDate: false,
            notify: false,
        );
    }

    private function tell(User $user, CommsEvent $event, string $urgency, string $key): void
    {
        $this->container->make(Notifier::class)->store($user, 'signal', $urgency, null, null, $key, [$event->id]);
    }
}
