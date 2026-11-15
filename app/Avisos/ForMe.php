<?php

namespace App\Avisos;

use App\Alerts\AlertSource;
use App\Alerts\AlertStatus;
use App\Models\InboxItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The Para mí inbox: the person's avisos, with a filter and a pending count.
 */
class ForMe
{
    /** @var list<AlertSource> */
    public const SOURCES = [AlertSource::Slack, AlertSource::Gmail, AlertSource::Jira, AlertSource::Notion];

    /**
     * @return array{
     *     pending_count: int,
     *     pending_label: string,
     *     filter: string,
     *     filters: list<array{key: string, label: string, count: int}>,
     *     items: list<array<string, mixed>>
     * }
     */
    public function present(User $user, ?string $filter = null): array
    {
        $items = $this->open($user);
        $filter = $this->filter($filter);
        $shown = $filter === 'all'
            ? $items
            : $items->filter(fn (InboxItem $item): bool => $item->source->value === $filter)->values();
        $pending = $shown->filter(fn (InboxItem $item): bool => $item->read_at === null)->count();

        return [
            'pending_count' => $pending,
            'pending_label' => $this->label($pending),
            'filter' => $filter,
            'filters' => $this->filters($items),
            'items' => $shown->values()->map(fn (InboxItem $item, int $index): array => $this->row($item, $index + 1))->all(),
        ];
    }

    /**
     * What the open screen uses to ring and, when asked, to read an aviso aloud.
     *
     * @return array<string, array{sound: string, read_aloud: bool}>
     */
    public function sounds(User $user): array
    {
        $saved = $user->alertSettings()
            ->whereIn('source', self::SOURCES)
            ->get()
            ->keyBy(fn ($setting): string => $setting->source->value);
        $sounds = [];

        foreach (self::SOURCES as $source) {
            $setting = $saved->get($source->value);
            $sounds[$source->value] = [
                'sound' => $setting?->sound ?: $source->defaultSound(),
                'read_aloud' => $setting === null ? true : (bool) $setting->read_aloud,
            ];
        }

        return $sounds;
    }

    /**
     * @return Collection<int, InboxItem>
     */
    private function open(User $user): Collection
    {
        return $user->inboxItems()
            ->whereIn('source', self::SOURCES)
            ->where('status', AlertStatus::Pending)
            ->orderByRaw('read_at is null desc')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  Collection<int, InboxItem>  $items
     * @return list<array{key: string, label: string, count: int}>
     */
    private function filters(Collection $items): array
    {
        $filters = [['key' => 'all', 'label' => 'Todo', 'count' => $items->count()]];

        foreach (self::SOURCES as $source) {
            $filters[] = [
                'key' => $source->value,
                'label' => $source->label(),
                'count' => $items->filter(fn (InboxItem $item): bool => $item->source === $source)->count(),
            ];
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(InboxItem $item, int $index): array
    {
        return [
            'id' => $item->id,
            'index' => $index,
            'source' => $item->source->value,
            'source_label' => $item->source->label(),
            'actor_name' => $item->actor_name,
            'title' => $item->title,
            'body' => (string) $item->body,
            'why' => $item->why,
            'received_at' => $item->received_at->toIso8601String(),
            'read' => $item->read_at !== null,
            'external_url' => $item->external_url,
        ];
    }

    private function filter(?string $filter): string
    {
        $allowed = array_map(fn (AlertSource $source): string => $source->value, self::SOURCES);

        return in_array($filter, $allowed, true) ? $filter : 'all';
    }

    private function label(int $pending): string
    {
        return match ($pending) {
            0 => 'Al día',
            1 => '1 pendiente',
            default => "{$pending} pendientes",
        };
    }
}
