<?php

namespace App\Voice\OpenUi\Data;

use App\Alerts\AlertStatus;
use App\Models\InboxItem;
use App\Models\User;

/**
 * The person's alerts as rows: where they came from, who, why and how they
 * stand, to count by source, by person or by day.
 */
class AlertRows implements Dataset
{
    public function name(): string
    {
        return 'alerts';
    }

    public function description(): string
    {
        return 'Avisos como filas (source, actor, title, why, status, received). status: pending para los pendientes, all para los de los últimos days días.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'all']],
                'days' => ['type' => 'number', 'description' => 'Con all, cuántos días atrás (por defecto 7, como mucho 90).'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:pending,all'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function fields(): array
    {
        return [
            'source' => 'text',
            'actor' => 'text',
            'title' => 'text',
            'why' => 'text',
            'status' => 'text',
            'received' => 'date',
        ];
    }

    public function rows(User $user, array $arguments, string $timezone): array
    {
        $pending = ($arguments['status'] ?? 'pending') === 'pending';

        return $user->inboxItems()
            ->when($pending, fn ($items) => $items->where('status', AlertStatus::Pending))
            ->when(! $pending, fn ($items) => $items->where('received_at', '>=', now()->subDays((int) ($arguments['days'] ?? 7))))
            ->latest('received_at')
            ->limit(Datasets::MAX_ROWS)
            ->get()
            ->map(fn (InboxItem $item): array => [
                'source' => $item->source->label(),
                'actor' => $item->actor_name ?? 'Desconocido',
                'title' => (string) $item->title,
                'why' => (string) $item->why,
                'status' => $item->stateLabel(),
                'received' => $item->received_at->setTimezone($timezone)->toDateString(),
            ])
            ->all();
    }
}
