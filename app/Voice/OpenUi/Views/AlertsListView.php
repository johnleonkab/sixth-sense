<?php

namespace App\Voice\OpenUi\Views;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Models\InboxItem;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\OpenUi;
use App\Voice\OpenUi\Ref;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Alerts, numbered so the person can say "revisa el 2": where and who, why,
 * and how the reply engine proposes to resolve each. A click reviews the
 * alert, or shows the day of a meeting.
 *
 * The pending list and the history ask for their rows while on screen, so
 * they stay current; a search shows the alerts it found.
 */
class AlertsListView
{
    /** How often the pending list asks again, in seconds. */
    public const REFRESH = 60;

    /** How many more alerts the history loads each time. */
    public const PAGE = 30;

    /** @var array<string, string> */
    public const HISTORY_TABS = ['all' => 'Todos', 'pending' => 'Pendientes', 'addressed' => 'Atendidos', 'routine' => 'Sin importancia'];

    /**
     * What waits for an answer (or everything lately, with status all),
     * asked for again every minute.
     *
     * @param  array{status: string, source?: string, limit: int}  $arguments
     */
    public static function live(User $user, string $title, array $arguments): Canvas
    {
        $view = new ViewBuilder($title);
        $alerts = $view->query('alerts', $arguments, self::REFRESH);
        $view->add('ItemList', [self::items($alerts), 'Nada pendiente.']);

        return $view->canvas($user, 'Avisos', 'focus');
    }

    /**
     * Every alert that came in, newest first: tabs to filter it and a button
     * that loads more, without asking the assistant.
     */
    public static function history(User $user, string $filter): Canvas
    {
        $view = new ViewBuilder('Historial de avisos');
        $status = $view->variable('status', $filter);
        $limit = $view->variable('limit', self::PAGE);
        $view->add('Tabs', [array_map(fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::HISTORY_TABS), self::HISTORY_TABS), $status]);
        $alerts = $view->query('alerts', ['status' => $status, 'limit' => $limit], self::REFRESH);
        $view->add('ItemList', [self::items($alerts), 'No hay avisos aquí.']);
        $view->add('Button', ['Cargar más', new Ref('@Set('.$limit->name.', '.$limit->name.' + '.self::PAGE.')')], when: new Ref("@Count({$alerts->name}.rows) >= {$limit->name}"));

        return $view->canvas($user, 'Avisos', 'wide');
    }

    /**
     * Each row of the alerts dataset as an item that opens by its ref.
     */
    private static function items(Ref $alerts): Ref
    {
        return new Ref(OpenUi::call('@Each', [new Ref("{$alerts->name}.rows"), 'a', array_map(fn (string $field): Ref => new Ref("a.{$field}"), [
            'n' => 'n',
            'title' => 'headline',
            'eyebrow' => 'context',
            'snippet' => 'proposal',
            'meta' => 'when',
            'status' => 'closed',
            'highlight' => 'pending',
            'ref' => 'ref',
        ])]));
    }

    /**
     * @param  Collection<int, InboxItem>  $alerts
     */
    public static function canvas(User $user, string $title, Collection $alerts, string $timezone): Canvas
    {
        $view = new ViewBuilder($title);
        $items = [];

        foreach ($alerts->values() as $index => $alert) {
            $n = $index + 1;
            $closed = in_array($alert->status, [AlertStatus::Resolved, AlertStatus::Dismissed], true);
            $proposal = $alert->proposal['summary'] ?? null;

            $items[] = [
                'n' => $n,
                'title' => (string) ($alert->why ?: $alert->title),
                'eyebrow' => implode(' · ', array_filter([$alert->source->label(), $alert->actor_name, $alert->title])),
                'snippet' => $proposal ? "→ {$proposal}" : null,
                'meta' => Dates::relative(CarbonImmutable::parse($alert->received_at), $timezone),
                'status' => $closed ? ($alert->status === AlertStatus::Resolved ? 'Resuelta' : 'Descartada') : null,
                'highlight' => $alert->status === AlertStatus::Pending ?: null,
            ];

            $alert->source === AlertSource::Calendar
                ? $view->openable($n, (string) $alert->title, 'show_calendar', ['start_date' => substr((string) ($alert->payload['starts_at'] ?? $alert->received_at->toIso8601String()), 0, 10)])
                : $view->openable($n, (string) $alert->title, 'review_alert', ['alert_id' => $alert->id]);
        }

        $view->add('ItemList', [$items, 'Nada pendiente.']);

        return $view->canvas($user, 'Avisos', 'focus');
    }
}
