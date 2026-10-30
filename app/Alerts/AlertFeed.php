<?php

namespace App\Alerts;

use App\Models\InboxItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the screen lists: everything that came in lately (important or not,
 * still being judged or done) and every alert still waiting for an answer.
 * The browser asks for it again whenever it may have missed something, so it
 * never drifts from what really happened.
 */
class AlertFeed
{
    private const RECENT_HOURS = 48;

    private const LIMIT = 40;

    public const HISTORY_FILTERS = ['all', 'pending', 'addressed', 'routine'];

    private const HISTORY_PAGE = 30;

    /**
     * Everything that ever came in, newest first, a page at a time: all of
     * it, what still waits for an answer, what was addressed, or what needed
     * nothing.
     *
     * @return array{items: list<array<string, mixed>>, next: ?string}
     */
    public function history(User $user, string $filter = 'all', ?string $before = null): array
    {
        $items = $this->filtered($user, $filter)
            ->when($before !== null, function (Builder $items) use ($before): void {
                // "time|id": what came before it, and what came at the same moment with a lower id.
                [$time, $id] = array_pad(explode('|', $before, 2), 2, PHP_INT_MAX);
                $items->where(fn (Builder $older) => $older
                    ->where('received_at', '<', CarbonImmutable::parse($time))
                    ->orWhere(fn (Builder $same) => $same->where('received_at', CarbonImmutable::parse($time))->where('id', '<', (int) $id)));
            })
            ->limit(self::HISTORY_PAGE + 1)
            ->get();

        $page = $items->take(self::HISTORY_PAGE);
        $last = $page->last();

        return [
            'items' => $page->map(fn (InboxItem $item): array => $item->toAlert())->values()->all(),
            'next' => $items->count() > self::HISTORY_PAGE ? $last->received_at->utc()->toIso8601String().'|'.$last->id : null,
        ];
    }

    /**
     * The person's alerts under one of the history filters, newest first.
     *
     * @return HasMany<InboxItem, User>
     */
    public function filtered(User $user, string $filter): HasMany
    {
        return $user->inboxItems()
            ->where(fn (Builder $items) => $items->whereNull('resolution')->orWhere('resolution', '!=', 'superseded'))
            ->when($filter === 'pending', fn (Builder $items) => $items->where('status', AlertStatus::Pending))
            ->when($filter === 'addressed', fn (Builder $items) => $items->whereIn('status', [AlertStatus::Resolved, AlertStatus::Dismissed]))
            ->when($filter === 'routine', fn (Builder $items) => $items->where('triage', Triage::Routine))
            ->orderByDesc('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function for(User $user): array
    {
        return $user->inboxItems()
            ->where(fn (Builder $items) => $items
                ->where('received_at', '>=', now()->subHours(self::RECENT_HOURS))
                ->orWhere('status', AlertStatus::Pending))
            ->where(fn (Builder $items) => $items->whereNull('resolution')->orWhere('resolution', '!=', 'superseded'))
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (InboxItem $item): array => $item->toAlert())
            ->all();
    }
}
