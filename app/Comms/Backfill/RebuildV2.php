<?php

namespace App\Comms\Backfill;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Comms\Jobs\Consuming;
use App\Comms\Linking\PipelineV2;
use App\Comms\Notify\Notifier;
use App\Comms\Notify\Silenced;
use App\Comms\Windows\SettleBuffer;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsIntentParticipant;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsPlaybookRun;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsQuestion;
use App\Models\Comms\CommsSegment;
use App\Models\Comms\CommsSignal;
use App\Models\Comms\CommsWatcher;
use App\Models\Comms\CommsWindow;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Replaces one person's intent projections with a v2 reading of the last
 * 30 days. Opens the pipeline creates are marked as backfill. Opens it does
 * not reproduce are asked about once.
 */
class RebuildV2
{
    public function __construct(
        private Passes $passes,
        private SettleBuffer $buffer,
        private PipelineV2 $pipeline,
        private EventLog $log,
        private Notifier $notifier,
        private Consuming $consuming,
        private Silenced $silenced,
    ) {}

    public function run(User $user): void
    {
        $saved = config('comms.linking.v2');
        $wasConsuming = $this->consuming->active;
        $wasSilenced = $this->silenced->active;

        try {
            $this->consuming->active = true;
            $this->silenced->active = true;
            config(['comms.linking.v2' => true]);

            $open = $this->openIntents($user);
            $this->clear($user);

            foreach (Passes::ORDER as $pass) {
                $this->passes->run($user, $pass);
            }

            $this->resettle($user);
            CommsIntent::query()->whereBelongsTo($user)->where('state', 'open')->update(['from_backfill' => true]);
            $this->silenced->active = false;
            $this->askAboutMissing($user, $open);
        } finally {
            config(['comms.linking.v2' => $saved]);
            $this->consuming->active = $wasConsuming;
            $this->silenced->active = $wasSilenced;
        }
    }

    /**
     * @return list<array{key: string, what: string, importance: int}>
     */
    private function openIntents(User $user): array
    {
        return CommsIntent::query()
            ->whereBelongsTo($user)
            ->where('state', 'open')
            ->orderBy('id')
            ->get()
            ->map(fn (CommsIntent $intent): array => [
                'key' => $intent->key,
                'what' => $intent->what,
                'importance' => (int) $intent->importance,
            ])
            ->all();
    }

    private function clear(User $user): void
    {
        CommsLinkDecision::query()->whereBelongsTo($user)->delete();
        CommsNotification::query()->whereBelongsTo($user)->delete();
        CommsQuestion::query()->whereBelongsTo($user)->delete();
        CommsWatcher::query()->whereBelongsTo($user)->delete();
        CommsSignal::query()->whereBelongsTo($user)->delete();
        CommsConversationLink::query()->whereBelongsTo($user)->delete();
        CommsProposal::query()->whereBelongsTo($user)->delete();
        CommsPlaybookRun::query()->whereBelongsTo($user)->delete();
        CommsSegment::query()->whereBelongsTo($user)->delete();
        CommsWindow::query()->whereBelongsTo($user)->delete();
        CommsIntentParticipant::query()->whereBelongsTo($user)->delete();
        CommsIntent::query()->whereBelongsTo($user)->update(['alternative_intent_id' => null]);
        CommsIntent::query()->whereBelongsTo($user)->delete();
    }

    private function resettle(User $user): void
    {
        $start = CarbonImmutable::now()->subDays((int) config('comms.backfill.days'));
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->whereNull('deleted_at')
            ->where('occurred_at', '>=', $start)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $before = (int) CommsEvent::query()->whereBelongsTo($user)->max('id');

        foreach ($messages as $message) {
            $this->buffer->add($message);
        }

        $this->buffer->due($user, CarbonImmutable::now());

        $events = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::WindowSettled)
            ->where('id', '>', $before)
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $this->pipeline->process($event);
        }
    }

    /**
     * @param  list<array{key: string, what: string, importance: int}>  $open
     */
    private function askAboutMissing(User $user, array $open): void
    {
        $reproduced = CommsIntent::query()
            ->whereBelongsTo($user)
            ->pluck('what')
            ->map(fn (mixed $what): string => trim((string) $what))
            ->all();
        $missing = [];

        foreach ($open as $row) {
            if (in_array(trim($row['what']), $reproduced, true)) {
                continue;
            }

            $key = $row['key'];

            if (CommsIntent::query()->whereBelongsTo($user)->where('key', $key)->exists()) {
                $key .= ':before';
            }

            $missing[] = CommsIntent::query()->create([
                'user_id' => $user->id,
                'key' => $key,
                'what' => $row['what'],
                'state' => 'stale',
                'importance' => $row['importance'],
                'flags' => [],
                'from_backfill' => false,
            ]);
        }

        if ($missing === []) {
            return;
        }

        $event = $this->log->append(
            user: $user,
            type: EventType::ClockStale,
            source: Source::Clock,
            externalId: 'rebuild:'.CarbonImmutable::now()->toDateString(),
            payload: null,
            normalized: ['reason' => 'rebuild'],
            causation: null,
            occurredAt: CarbonImmutable::now(),
            dispatch: false,
        );
        $this->notifier->batch($user, $missing, $event);
    }
}
