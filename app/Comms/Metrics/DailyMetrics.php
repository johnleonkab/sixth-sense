<?php

namespace App\Comms\Metrics;

use App\Comms\Events\EventType;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsMetric;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One row per user per day, counted from the log and the projections.
 * Replay does not rebuild this table.
 */
class DailyMetrics
{
    public function write(User $user, CarbonImmutable $day): CommsMetric
    {
        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $messages = CommsMessage::query()
            ->whereBelongsTo($user)
            ->where('direction', 'in')
            ->whereBetween('occurred_at', [$start, $end])
            ->get();
        $modelNoise = $this->modelNoise($user, $start, $end);
        $droppedByRule = 0;
        $droppedByModel = 0;

        foreach ($messages as $message) {
            if (! $this->dropped($message)) {
                continue;
            }

            if (isset($modelNoise[$message->event_id])) {
                $droppedByModel++;
            } else {
                $droppedByRule++;
            }
        }

        $applied = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::StateApplied)
            ->whereBetween('occurred_at', [$start, $end])
            ->get();
        $opened = 0;
        $settled = 0;

        foreach ($applied as $event) {
            foreach ($event->normalized['results'] ?? [] as $result) {
                if (! is_array($result) || ($result['status'] ?? '') !== 'accepted') {
                    continue;
                }

                if (($result['op'] ?? '') === 'open') {
                    $opened++;
                }

                if (($result['op'] ?? '') === 'settle') {
                    $settled++;
                }
            }
        }

        $corrections = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::UserCorrected)
            ->whereBetween('occurred_at', [$start, $end])
            ->get();
        $byAction = [];

        foreach ($corrections as $correction) {
            $action = (string) ($correction->normalized['action'] ?? 'unknown');
            $byAction[$action] = ($byAction[$action] ?? 0) + 1;

            if (in_array($action, ['close', 'merge'], true)) {
                $settled++;
            }
        }

        return CommsMetric::query()->updateOrCreate(
            ['user_id' => $user->id, 'day' => $start->toDateString()],
            [
                'messages_in' => $messages->count(),
                'dropped_by_rule' => $droppedByRule,
                'dropped_by_model' => $droppedByModel,
                'intents_opened' => $opened,
                'intents_settled' => $settled,
                'uncertain_links' => CommsConversationLink::query()
                    ->whereBelongsTo($user)
                    ->where('uncertain', true)
                    ->whereHas('stateEvent', fn ($query) => $query->whereBetween('occurred_at', [$start, $end]))
                    ->count(),
                'corrections' => $byAction,
                'misses' => $this->misses($user, $corrections),
            ],
        );
    }

    /**
     * @return array<int, true>
     */
    private function modelNoise(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $hits = [];
        $decisions = CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::ModelDecided)
            ->whereBetween('occurred_at', [$start, $end])
            ->get();

        foreach ($decisions as $decision) {
            if (($decision->normalized['task'] ?? null) !== 'triage') {
                continue;
            }

            if (($decision->normalized['output']['label'] ?? null) !== 'noise') {
                continue;
            }

            foreach ($decision->normalized['event_ids'] ?? [] as $id) {
                if (is_int($id)) {
                    $hits[$id] = true;
                }
            }
        }

        return $hits;
    }

    private function dropped(CommsMessage $message): bool
    {
        $flags = $message->flags ?? [];
        $label = $flags['label'] ?? null;

        return ($flags['noise'] ?? false) === true
            || ($flags['cc_only'] ?? false) === true
            || in_array($label, ['noise', 'cc_only', 'delivery'], true);
    }

    /**
     * @param  iterable<int, CommsEvent>  $corrections
     */
    private function misses(User $user, iterable $corrections): int
    {
        $misses = 0;

        foreach ($corrections as $correction) {
            $data = $correction->normalized ?? [];
            $refs = match ($data['action'] ?? '') {
                'rematch' => [['source' => $data['message_source'] ?? null, 'external_id' => $data['message_external_id'] ?? null]],
                'split' => is_array($data['messages'] ?? null) ? $data['messages'] : [],
                default => [],
            };

            foreach ($refs as $ref) {
                if (! is_array($ref)) {
                    continue;
                }

                $message = CommsMessage::query()
                    ->whereBelongsTo($user)
                    ->where('source', $ref['source'] ?? '')
                    ->where('external_id', $ref['external_id'] ?? '')
                    ->first();

                if (($message?->flags['noise'] ?? false) === true) {
                    $misses++;
                }
            }
        }

        return $misses;
    }
}
