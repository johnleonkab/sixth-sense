<?php

namespace App\Voice\OpenUi\Views;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Models\InboxItem;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Alerts, numbered so the person can say "revisa el 2": where and who, why,
 * and how the reply engine proposes to resolve each. A click reviews the
 * alert, or shows the day of a meeting.
 */
class AlertsListView
{
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
