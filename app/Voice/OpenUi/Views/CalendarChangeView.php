<?php

namespace App\Voice\OpenUi\Views;

use App\Models\PendingAction;
use App\Models\User;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;
use Carbon\CarbonImmutable;

/**
 * A proposed calendar change on its event: the event as it is, what will
 * change as a draft, and nothing happens until the person says "sí".
 */
class CalendarChangeView
{
    /** @var array<string, array{source: string, verb: string, done: string}> */
    private const KINDS = [
        'invite' => ['source' => 'Invitación', 'verb' => 'invitar', 'done' => 'Invitaciones enviadas'],
        'summary' => ['source' => 'Resumen', 'verb' => 'añadir', 'done' => 'Resumen añadido'],
        'create' => ['source' => 'Reunión nueva', 'verb' => 'convocar', 'done' => 'Reunión convocada'],
        'cancel' => ['source' => 'Cancelación', 'verb' => 'cancelar', 'done' => 'Reunión cancelada'],
        'respond' => ['source' => 'Respuesta', 'verb' => 'responder', 'done' => 'Respuesta enviada'],
        'update' => ['source' => 'Cambio', 'verb' => 'cambiar', 'done' => 'Reunión cambiada'],
    ];

    /** @var array<string, string> */
    private const RESPONSES = ['accepted' => 'Asistiré', 'declined' => 'No asistiré', 'tentative' => 'Quizá', 'needsAction' => 'Sin responder'];

    /**
     * @param  ?array<string, mixed>  $event  The event as it is now.
     * @param  array<string, mixed>  $details  What the change shows: new_event, updated_event, changes, conflicts…
     */
    public static function canvas(User $user, PendingAction $action, string $kind, ?array $event, array $details): Canvas
    {
        $view = new ViewBuilder($action->label);
        $view->add('Text', ['Borrador · aún no se ha cambiado nada.', 'muted']);

        if ($event !== null && $kind !== 'create') {
            self::card($view, $event);
        }

        match ($kind) {
            'create' => isset($details['new_event']) ? $view->add('Draft', ['Reunión nueva · borrador', self::describe($details['new_event'], withDescription: true)]) : null,
            'update' => self::update($view, $details),
            'invite' => $view->add('Draft', ['Nuevos invitados', implode(', ', array_map(self::name(...), $details['new_people'] ?? []))]),
            'summary' => self::summary($view, $details),
            'cancel' => $view->add('Text', ['Se borrará de tu calendario'.(($details['notified'] ?? 0) > 0 ? ' y se avisará a '.($details['notified'] === 1 ? '1 invitado' : "{$details['notified']} invitados") : '').'.', 'warn']),
            'respond' => self::respond($view, $details),
            default => null,
        };

        if (($details['conflicts'] ?? []) !== []) {
            $view->add('Text', ['Choca con '.implode(', ', array_map(
                fn (array $conflict): string => "«{$conflict['title']}» (".self::time($conflict['start']).'–'.self::time($conflict['end']).')',
                $details['conflicts'],
            )).'.', 'warn']);
        }

        $kindLabels = self::KINDS[$kind] ?? ['source' => 'Cambio', 'verb' => 'aplicar', 'done' => 'Hecho'];
        $view->proposal($action, $kindLabels['verb'], $kindLabels['done']);

        return $view->canvas($user, "Calendar · {$kindLabels['source']}", 'focus');
    }

    /**
     * The event as it is: when, where and who, each with their answer.
     *
     * @param  array<string, mixed>  $event
     */
    private static function card(ViewBuilder $view, array $event): void
    {
        $children = [$view->add('Text', [self::when($event)], top: false)];
        $where = implode(' · ', array_filter([$event['location'] ?? null, ($event['meeting_url'] ?? null) ? 'Google Meet' : null]));

        if ($where !== '') {
            $children[] = $view->add('Text', [$where, 'muted'], top: false);
        }

        if (($event['attendees'] ?? []) !== []) {
            $children[] = $view->add('Chips', [array_map(fn (array $person): string => self::name($person).match ($person['response'] ?? null) {
                'accepted' => ' ✓',
                'declined' => ' ✗',
                'tentative' => ' ?',
                default => '',
            }, $event['attendees'])], top: false);
        }

        $view->add('Card', [$event['title'], $children]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function update(ViewBuilder $view, array $details): void
    {
        if (($details['changes'] ?? []) !== []) {
            $view->add('Table', [['', 'Antes', 'Después'], array_map(fn (array $change): array => [$change['label'], $change['before'] ?? '—', $change['after']], $details['changes'])]);
        }

        if (isset($details['updated_event'])) {
            $view->add('Draft', ['Así quedará', self::describe($details['updated_event'])]);
        }

        $view->add('Text', [($details['notify'] ?? false) ? 'Se avisará a los invitados del cambio.' : 'No se avisará a los invitados.', 'muted']);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function summary(ViewBuilder $view, array $details): void
    {
        if (filled($details['existing_description'] ?? null)) {
            $view->add('Quote', [mb_substr((string) $details['existing_description'], 0, 400), 'Ya tiene']);
        }

        $view->add('Draft', ['— '.($details['heading'] ?? 'Resumen').' —', (string) ($details['summary'] ?? '')]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function respond(ViewBuilder $view, array $details): void
    {
        $before = $details['previous_response'] ?? null;
        $after = $details['response_label'] ?? self::RESPONSES[$details['response'] ?? ''] ?? '';
        $label = $before && $before !== ($details['response'] ?? null) ? 'Tu respuesta, antes «'.(self::RESPONSES[$before] ?? $before).'»' : 'Tu respuesta';

        $view->add('Draft', [$label, $after.(filled($details['comment'] ?? null) ? "\n«{$details['comment']}»" : '')]);
    }

    /**
     * An event in a few lines, for a draft.
     *
     * @param  array<string, mixed>  $event
     */
    private static function describe(array $event, bool $withDescription = false): string
    {
        return implode("\n", array_filter([
            $event['title'] ?? null,
            self::when($event),
            implode(' · ', array_filter([
                isset($event['calendar']) ? "Calendario de {$event['calendar']}" : null,
                $event['location'] ?? null,
                ($event['meeting_url'] ?? null) || ($event['video_call'] ?? false) ? 'Google Meet' : null,
            ])) ?: null,
            ($event['attendees'] ?? []) !== [] ? 'Con '.implode(', ', array_map(self::name(...), $event['attendees'])) : null,
            $withDescription ? ($event['description'] ?? null) : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function when(array $event): string
    {
        $start = CarbonImmutable::parse($event['start'])->locale('es');
        $day = ucfirst($start->isoFormat('dddd D [de] MMMM'));

        return ($event['all_day'] ?? false) ? "{$day} · todo el día" : "{$day} · ".self::time($event['start']).'–'.self::time($event['end']);
    }

    private static function time(string $iso): string
    {
        return substr($iso, 11, 5);
    }

    /**
     * @param  array{name?: ?string, email?: ?string}  $person
     */
    private static function name(array $person): string
    {
        return (string) (($person['name'] ?? null) ?: ($person['email'] ?? ''));
    }
}
