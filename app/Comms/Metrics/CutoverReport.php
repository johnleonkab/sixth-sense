<?php

namespace App\Comms\Metrics;

use App\Comms\Events\EventType;
use App\Comms\Gate\ProposalStatus;
use App\Models\AiUsage;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsNotification;
use App\Models\Comms\CommsProposal;
use App\Models\Comms\CommsSearch;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * One day of cutover numbers. Every line has a number, including zeroes.
 */
class CutoverReport
{
    public function __construct(private DailyMetrics $metrics) {}

    /**
     * @return list<string>
     */
    public function lines(User $user, CarbonImmutable $day, bool $triggers = false): array
    {
        $start = $day->startOfDay();
        $end = $day->endOfDay();
        $row = $this->metrics->write($user, $start);
        $incoming = $row->messages_in;
        $corrections = array_sum($row->corrections ?? []);
        $now = CommsNotification::query()
            ->whereBelongsTo($user)
            ->where('urgency', 'now')
            ->whereBetween('created_at', [$start, $end])
            ->get();
        $notUrgent = $now->where('feedback', 'not_urgent')->count();
        $proposals = CommsProposal::query()
            ->whereBelongsTo($user)
            ->where(fn ($query) => $query
                ->whereBetween('created_at', [$start, $end])
                ->orWhereBetween('decided_at', [$start, $end]))
            ->get();
        $accepted = $proposals->whereIn('status', [ProposalStatus::Approved, ProposalStatus::Executed])->count();
        $edited = $proposals->where('status', ProposalStatus::Edited)->count();
        $rejected = $proposals->where('status', ProposalStatus::Rejected)->count();
        $decided = $accepted + $edited + $rejected;
        $reasons = $proposals
            ->map(fn (CommsProposal $proposal): string => $proposal->deny_reason ?: $proposal->status->value)
            ->countBy();
        $usage = AiUsage::query()->whereBelongsTo($user)->whereBetween('created_at', [$start, $end])->get();
        $byMessage = [];

        foreach ($usage as $call) {
            $key = (string) ($call->event_ids[0] ?? 'none');
            $byMessage[$key] = ($byMessage[$key] ?? 0) + (int) $call->latency_ms;
        }

        $lines = [
            'dropped by rule: '.$this->share($row->dropped_by_rule, $incoming).'%',
            'dropped by model: '.$this->share($row->dropped_by_model, $incoming).'%',
            'misses: '.$row->misses,
            'corrections per 100: '.$this->share($corrections, $incoming),
            'now not urgent: '.$notUrgent.' of '.$now->count(),
            'proposals accepted: '.$this->share($accepted, $decided).'%',
            'proposals edited: '.$this->share($edited, $decided).'%',
            'proposals rejected: '.$this->share($rejected, $decided).'%',
            'proposal reasons: '.($reasons->isEmpty() ? 'none 0' : $reasons->map(fn (int $count, string $reason): string => $reason.' '.$count)->implode(', ')),
            'cost: '.number_format((float) $usage->sum('cost'), 2, '.', ''),
            'latency message p50: '.$this->percentile(array_values($byMessage), 50).' p95: '.$this->percentile(array_values($byMessage), 95),
        ];

        $tasks = $usage->groupBy('task');

        if ($tasks->isEmpty()) {
            $lines[] = 'latency task none p50: 0 p95: 0';
        }

        foreach ($tasks as $task => $calls) {
            $latencies = $calls->map(fn (AiUsage $call): int => (int) $call->latency_ms)->all();
            $lines[] = 'latency task '.$task.' p50: '.$this->percentile($latencies, 50).' p95: '.$this->percentile($latencies, 95);
        }

        $searches = CommsSearch::query()
            ->whereBelongsTo($user)
            ->whereBetween('created_at', [$start, $end])
            ->pluck('latency_ms')
            ->map(fn (mixed $ms): int => (int) $ms)
            ->all();
        $lines[] = 'search p95: '.$this->percentile($searches, 95);

        if ($triggers) {
            $unmatched = CommsEvent::query()
                ->whereBelongsTo($user)
                ->where('type', EventType::ModelDecided)
                ->whereBetween('occurred_at', [$start, $end])
                ->get()
                ->filter(fn (CommsEvent $event): bool => ($event->normalized['task'] ?? null) === 'route')
                ->count();
            $lines[] = 'unmatched triggers: '.$unmatched;
        }

        return $lines;
    }

    private function share(int $part, int $whole): int
    {
        if ($whole === 0) {
            return 0;
        }

        return (int) round($part / $whole * 100);
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, int $percent): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $index = (int) ceil($percent / 100 * count($values)) - 1;

        return $values[max(0, $index)];
    }
}
