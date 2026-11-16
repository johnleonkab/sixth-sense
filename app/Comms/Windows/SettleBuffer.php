<?php

namespace App\Comms\Windows;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Groups messages into one open window per conversation and closes it when
 * the conversation goes quiet, stays open too long, or a message is urgent.
 * The close is a window.settled event, so a replay builds the same windows.
 */
class SettleBuffer
{
    public function __construct(
        private EventLog $log,
        private UrgentRules $rules,
        private WindowAssembler $assembler,
    ) {}

    /**
     * Put a message in the open window for its conversation. An edit of a
     * message already in a closed window opens a new one while it is recent.
     */
    public function add(CommsMessage $message, bool $edited = false, ?CarbonInterface $at = null): ?CommsWindow
    {
        if (! $this->enabled() || $message->deleted_at !== null) {
            return null;
        }

        $message->loadMissing('user', 'author');

        if ($edited) {
            return $this->reopen($message, CarbonImmutable::parse($at ?? $message->occurred_at));
        }

        [$window, $inserted] = $this->place($message);

        if ($inserted && $window->state === 'open' && $this->rules->matches($message->user, $message)) {
            $this->settle($window, CarbonImmutable::parse($message->occurred_at));
            $window->refresh();
        }

        return $window;
    }

    /**
     * Settle open windows whose quiet time, or the maximum open time, has passed.
     */
    public function due(User $user, CarbonInterface $now): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $lock = Cache::lock('comms-decide:'.$user->id, 300);

        if (! $lock->get()) {
            return 0;
        }

        try {
            $now = CarbonImmutable::parse($now);
            $settled = 0;

            $windows = CommsWindow::query()
                ->whereBelongsTo($user)
                ->where('state', 'open')
                ->orderBy('id')
                ->get();

            foreach ($windows as $window) {
                if (! $this->isDue($window, $now)) {
                    continue;
                }

                $this->settle($window, $now);
                $settled++;
            }

            return $settled;
        } finally {
            $lock->release();
        }
    }

    /**
     * Rebuild windows from the log. Settling is not decided again: a stored
     * window.settled event closes the window the messages just reopened.
     */
    public function replay(CommsEvent $event): void
    {
        if (! $this->enabled() || $event->user === null) {
            return;
        }

        if (in_array($event->type, [EventType::MessageReceived, EventType::MessageSent], true)) {
            $message = CommsMessage::query()->where('event_id', $event->id)->first();

            if ($message !== null) {
                $this->place($message);
            }

            return;
        }

        if ($event->type === EventType::MessageEdited) {
            $message = CommsMessage::query()->where('event_id', $event->id)->first();

            if ($message !== null) {
                $this->reopen($message, CarbonImmutable::parse($event->occurred_at), settleUrgent: false);
            }

            return;
        }

        if ($event->type === EventType::WindowSettled) {
            $this->applySettled($event);
        }
    }

    /**
     * @return array{0: CommsWindow, 1: bool}
     */
    private function place(CommsMessage $message): array
    {
        $holding = $this->holding($message);

        if ($holding !== null) {
            return [$holding, false];
        }

        $group = ConversationGroup::of($message);

        $window = CommsWindow::query()
            ->where('user_id', $message->user_id)
            ->where('source', $message->source->value)
            ->where('group_ref', $group->ref)
            ->where('state', 'open')
            ->orderBy('id')
            ->first();

        if ($window === null) {
            $window = CommsWindow::query()->create([
                'user_id' => $message->user_id,
                'source' => $message->source->value,
                'group_ref' => $group->ref,
                'kind' => $group->kind,
                'message_ids' => [$message->id],
                'opened_at' => $message->occurred_at,
                'last_message_at' => $message->occurred_at,
                'state' => 'open',
            ]);

            return [$window, true];
        }

        $ids = array_values($window->message_ids ?? []);
        $ids[] = $message->id;
        $window->message_ids = $ids;

        if ($message->occurred_at->gt($window->last_message_at)) {
            $window->last_message_at = $message->occurred_at;
        }

        $window->save();

        return [$window, true];
    }

    private function reopen(CommsMessage $message, CarbonImmutable $at, bool $settleUrgent = true): ?CommsWindow
    {
        $open = CommsWindow::query()
            ->where('user_id', $message->user_id)
            ->where('state', 'open')
            ->orderBy('id')
            ->get()
            ->first(fn (CommsWindow $window): bool => in_array($message->id, $window->message_ids ?? [], true));

        if ($open !== null) {
            if ($at->gt($open->last_message_at)) {
                $open->last_message_at = $at;
                $open->save();
            }

            return $open;
        }

        $closed = CommsWindow::query()
            ->where('user_id', $message->user_id)
            ->whereIn('state', ['settled', 'processed'])
            ->orderByDesc('id')
            ->get()
            ->first(fn (CommsWindow $window): bool => in_array($message->id, $window->message_ids ?? [], true));

        if ($closed === null) {
            [$window] = $this->place($message);

            return $window;
        }

        $hours = (int) config('comms.edit.reprocess_hours', 24);

        if ($at->gt($message->occurred_at->addHours($hours))) {
            return null;
        }

        $group = ConversationGroup::of($message);

        $window = CommsWindow::query()->create([
            'user_id' => $message->user_id,
            'source' => $message->source->value,
            'group_ref' => $group->ref,
            'kind' => $group->kind,
            'message_ids' => [$message->id],
            'opened_at' => $at,
            'last_message_at' => $at,
            'state' => 'open',
        ]);

        if ($settleUrgent && $this->rules->matches($message->user, $message)) {
            $this->settle($window, $at);
            $window->refresh();
        }

        return $window;
    }

    private function isDue(CommsWindow $window, CarbonImmutable $now): bool
    {
        $quiet = in_array($window->kind, ['dm', 'thread'], true)
            ? (int) config('comms.settle.quiet_minutes_direct')
            : (int) config('comms.settle.quiet_minutes');

        $max = (int) config('comms.settle.max_minutes');

        return $window->last_message_at->lte($now->subMinutes($quiet))
            || $window->opened_at->lte($now->subMinutes($max));
    }

    private function settle(CommsWindow $window, CarbonImmutable $at): void
    {
        if ($window->state !== 'open') {
            return;
        }

        $window->loadMissing('user');
        $ids = array_values($window->message_ids ?? []);
        $event = $this->log->append(
            user: $window->user,
            type: EventType::WindowSettled,
            source: Source::from($window->source),
            externalId: 'window:'.$window->id,
            payload: null,
            normalized: [
                'window_id' => $window->id,
                'message_ids' => $ids,
                'lookback_ids' => $this->lookback($window),
                'source' => $window->source,
                'group_ref' => $window->group_ref,
            ],
            causation: null,
            occurredAt: $at,
        );

        $window->state = 'settled';
        $window->settled_event_id = $event->id;
        $window->save();
    }

    private function applySettled(CommsEvent $event): void
    {
        $data = $event->normalized ?? [];
        $query = CommsWindow::query()
            ->whereBelongsTo($event->user)
            ->where('state', 'open')
            ->orderBy('id');

        if (is_string($data['source'] ?? null) && is_string($data['group_ref'] ?? null)) {
            $query->where('source', $data['source'])->where('group_ref', $data['group_ref']);
        }

        $window = $query->first();

        if ($window === null) {
            return;
        }

        $window->state = 'settled';
        $window->settled_event_id = $event->id;
        $window->save();
    }

    /**
     * Earlier messages of the same conversation, capped by the lookback config.
     *
     * @return list<int>
     */
    private function lookback(CommsWindow $window): array
    {
        return array_values(array_map(
            fn (AssembledMessage $row): int => $row->message->id,
            array_filter(
                $this->assembler->for($window)->messages,
                fn (AssembledMessage $row): bool => ! $row->new,
            ),
        ));
    }

    private function holding(CommsMessage $message): ?CommsWindow
    {
        return CommsWindow::query()
            ->where('user_id', $message->user_id)
            ->orderBy('id')
            ->get()
            ->first(fn (CommsWindow $window): bool => in_array($message->id, $window->message_ids ?? [], true));
    }

    private function enabled(): bool
    {
        return (bool) config('comms.linking.v2');
    }
}
