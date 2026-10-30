<?php

namespace App\Voice\OpenUi\Data;

use App\Alerts\AlertFeed;
use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Models\InboxItem;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The person's alerts as rows: where they came from, who, why and how they
 * stand, to count by source, by person or by day, or to list them numbered
 * (n, ref) in an ItemList that opens each one.
 */
class AlertRows implements Dataset
{
    public function __construct(private AlertFeed $feed) {}

    public function name(): string
    {
        return 'alerts';
    }

    public function description(): string
    {
        return 'Avisos como filas, los más recientes primero. status: pending (por defecto, los que esperan respuesta), all, addressed (resueltos o descartados) o routine (sin importancia). '
            .'Para listarlos: ItemList con n, ref, headline, context, when, proposal, closed y pending (highlight).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => AlertFeed::HISTORY_FILTERS],
                'source' => ['type' => 'string', 'enum' => ['gmail', 'slack', 'calendar']],
                'days' => ['type' => 'number', 'description' => 'Solo los de los últimos N días (como mucho 90).'],
                'limit' => ['type' => 'number', 'description' => 'Cuántos, como mucho 500.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:'.implode(',', AlertFeed::HISTORY_FILTERS)],
            'source' => ['nullable', 'in:gmail,slack,calendar'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Datasets::MAX_ROWS],
        ];
    }

    public function fields(): array
    {
        return [
            'n' => 'number',
            'ref' => 'text',
            'source' => 'text',
            'actor' => 'text',
            'title' => 'text',
            'why' => 'text',
            'status' => 'text',
            'received' => 'date',
            'headline' => 'text',
            'context' => 'text',
            'when' => 'text',
            'proposal' => 'text',
            'closed' => 'text',
            'pending' => 'boolean',
        ];
    }

    public function rows(User $user, array $arguments, string $timezone): array
    {
        return $this->feed->filtered($user, $arguments['status'] ?? 'pending')
            ->when(isset($arguments['source']), fn (Builder $items) => $items->where('source', AlertSource::from($arguments['source'])))
            ->when(isset($arguments['days']), fn (Builder $items) => $items->where('received_at', '>=', now()->subDays((int) $arguments['days'])))
            ->limit($arguments['limit'] ?? Datasets::MAX_ROWS)
            ->get()
            ->values()
            ->map(fn (InboxItem $item, int $index): array => [
                'n' => $index + 1,
                'ref' => $item->source === AlertSource::Calendar
                    ? 'day:'.substr((string) ($item->payload['starts_at'] ?? $item->received_at->toIso8601String()), 0, 10)
                    : "alert:{$item->id}",
                'source' => $item->source->label(),
                'actor' => $item->actor_name ?? 'Desconocido',
                'title' => (string) $item->title,
                'why' => (string) $item->why,
                'status' => $item->stateLabel(),
                'received' => $item->received_at->setTimezone($timezone)->toDateString(),
                'headline' => (string) ($item->why ?: $item->title),
                'context' => implode(' · ', array_filter([$item->source->label(), $item->actor_name, $item->title])),
                'when' => Dates::relative(CarbonImmutable::parse($item->received_at), $timezone),
                'proposal' => isset($item->proposal['summary']) ? "→ {$item->proposal['summary']}" : '',
                'closed' => match ($item->status) {
                    AlertStatus::Resolved => 'Resuelta',
                    AlertStatus::Dismissed => 'Descartada',
                    default => '',
                },
                'pending' => $item->status === AlertStatus::Pending,
            ])
            ->all();
    }
}
