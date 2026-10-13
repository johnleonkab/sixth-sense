<?php

namespace App\Voice\Tools\Alerts;

use App\Alerts\AlertFeed;
use App\Alerts\AlertStatus;
use App\Alerts\Triage;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Every alert that came in, with where each stands: a history to look back on.
 */
class ShowAlertHistory implements VoiceTool
{
    public function __construct(private AlertFeed $feed) {}

    public function name(): string
    {
        return 'show_alert_history';
    }

    public function description(): string
    {
        return 'Muestra en pantalla el historial de avisos: todo lo que ha llegado de Gmail, Slack y el agendador, por días, con lo que ya está atendido bien marcado. '
            .'Úsala para "enséñame el historial de avisos", "¿qué avisos he tenido esta semana?", "¿qué he resuelto hoy?". filter: all, pending, addressed (resueltos o descartados) o routine (sin importancia).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['filter' => ['type' => 'string', 'enum' => AlertFeed::HISTORY_FILTERS]],
        ];
    }

    public function rules(): array
    {
        return ['filter' => ['nullable', 'in:'.implode(',', AlertFeed::HISTORY_FILTERS)]];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $filter = $arguments['filter'] ?? 'all';
        $page = $this->feed->history($user, $filter);
        $items = $user->inboxItems();

        return new ToolResult(
            output: [
                'shown_on_screen' => true,
                'pending' => (clone $items)->where('status', AlertStatus::Pending)->count(),
                'addressed_today' => (clone $items)->whereIn('status', [AlertStatus::Resolved, AlertStatus::Dismissed])->where('resolved_at', '>=', now($context->timezone())->startOfDay())->count(),
                'unimportant_today' => (clone $items)->where('triage', Triage::Routine)->where('received_at', '>=', now($context->timezone())->startOfDay())->count(),
            ],
            canvas: new Canvas('alerts.history', ['filter' => $filter, ...$page], layout: 'wide'),
        );
    }
}
