<?php

namespace App\Comms\Backfill;

use App\Comms\Evaluation\RetrievalMetric;
use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Jobs\Consuming;
use App\Comms\Linking\CriticVerdict;
use App\Comms\Linking\PipelineV2;
use App\Comms\People\Relation;
use App\Comms\Windows\SettleBuffer;
use App\Models\AiUsage;
use App\Models\Comms\CommsConversationLink;
use App\Models\Comms\CommsEvalRun;
use App\Models\Comms\CommsEvent;
use App\Models\Comms\CommsGoldenLabel;
use App\Models\Comms\CommsIntent;
use App\Models\Comms\CommsLinkDecision;
use App\Models\Comms\CommsMessage;
use App\Models\Comms\CommsPerson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Clones one user's recent messages onto a fresh user, builds context from
 * the first part of the window, and scores the linking pipeline on the rest.
 */
class BackfillEval
{
    public function __construct(
        private Passes $passes,
        private SettleBuffer $buffer,
        private PipelineV2 $pipeline,
        private EventLog $log,
        private RetrievalMetric $retrieval,
        private Consuming $consuming,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(User $source, ?string $critic): array
    {
        $saved = [
            'comms.linking.v2' => config('comms.linking.v2'),
            'comms.tasks.critic.provider' => config('comms.tasks.critic.provider'),
            'comms.tasks.critic.fallback' => config('comms.tasks.critic.fallback'),
        ];
        $wasConsuming = $this->consuming->active;
        $end = CarbonImmutable::now();
        $start = $end->subDays((int) config('comms.backfill.days'));
        $split = $start->addDays((int) config('comms.backfill.eval_split_day'));

        try {
            $this->consuming->active = true;

            if ($critic === 'deepseek' || $critic === 'gemini') {
                config([
                    'comms.tasks.critic.provider' => $critic,
                    'comms.tasks.critic.fallback' => $critic === 'deepseek' ? 'gemini' : 'deepseek',
                ]);
            }

            $eval = User::factory()->create([
                'name' => 'Evaluation',
                'email' => 'eval+'.$source->id.'-'.str_replace('.', '', uniqid('', true)).'@sixth-sense.test',
            ]);
            $people = $this->clonePeople($source, $eval);
            $messages = CommsMessage::query()
                ->whereBelongsTo($source)
                ->whereNull('deleted_at')
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<=', $end)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get();
            $early = $messages->filter(fn (CommsMessage $message): bool => $message->occurred_at->lt($split))->values();
            $late = $messages->filter(fn (CommsMessage $message): bool => $message->occurred_at->gte($split))->values();

            $this->cloneMessages($eval, $early->all(), $people);
            $this->passes->run($eval, 'identity');
            $this->passes->run($eval, 'topics');
            $this->passes->run($eval, 'patterns');
            $this->passes->run($eval, 'labels');
            $this->cloneMessages($eval, $late->all(), $people);
            $this->passes->run($eval, 'labels');

            $latencies = [];

            if ($late->isNotEmpty()) {
                config(['comms.linking.v2' => true]);
                $latencies = $this->settle($eval, $split);
            }

            $report = $this->report($source, $eval, $start, $split, $end, $early->count(), $late->count(), $latencies);
        } finally {
            config($saved);
            $this->consuming->active = $wasConsuming;
        }

        $path = $this->write($end, $report);
        $report['report_path'] = $path;

        CommsEvalRun::query()->create([
            'user_id' => $source->id,
            'kind' => 'backfill',
            'prompt_versions' => $report['prompt_versions'],
            'metrics' => $report,
            'passed' => null,
            'report_path' => $path,
        ]);

        return $report;
    }

    /**
     * @return array<int, int>
     */
    private function clonePeople(User $source, User $eval): array
    {
        $map = [];
        $people = CommsPerson::query()->whereBelongsTo($source)->orderBy('id')->get();

        foreach ($people as $person) {
            $copy = $person->replicate(['merged_into_id']);
            $copy->user_id = $eval->id;
            $copy->merged_into_id = null;
            $copy->save();
            $map[$person->id] = $copy->id;

            foreach ($person->identities as $identity) {
                $copied = $identity->replicate();
                $copied->user_id = $eval->id;
                $copied->person_id = $copy->id;
                $copied->save();
            }
        }

        foreach ($people as $person) {
            if ($person->merged_into_id === null || ! isset($map[$person->id], $map[$person->merged_into_id])) {
                continue;
            }

            CommsPerson::query()->whereKey($map[$person->id])->update([
                'merged_into_id' => $map[$person->merged_into_id],
            ]);
        }

        return $map;
    }

    /**
     * @param  list<CommsMessage>  $messages
     * @param  array<int, int>  $people
     */
    private function cloneMessages(User $eval, array $messages, array $people): void
    {
        foreach ($messages as $message) {
            $author = $message->author_person_id === null ? null : ($people[$message->author_person_id] ?? null);

            if ($message->author_person_id !== null && $author === null) {
                continue;
            }

            $event = $this->log->append(
                user: $eval,
                type: $message->direction === 'out' ? EventType::MessageSent : EventType::MessageReceived,
                source: $message->source,
                externalId: $message->external_id,
                payload: ['text' => $message->text],
                normalized: ['text' => $message->text, 'backfill' => true],
                causation: null,
                occurredAt: $message->occurred_at,
                dispatch: false,
            );

            $copy = $message->replicate(['window_id']);
            $copy->user_id = $eval->id;
            $copy->event_id = $event->id;
            $copy->author_person_id = $author;
            $copy->recipient_person_ids = $this->remap($message->recipient_person_ids ?? [], $people);
            $copy->window_id = null;
            $copy->save();
        }
    }

    /**
     * @param  list<mixed>  $ids
     * @param  array<int, int>  $people
     * @return list<int>
     */
    private function remap(array $ids, array $people): array
    {
        $mapped = [];

        foreach ($ids as $id) {
            if (is_numeric($id) && isset($people[(int) $id])) {
                $mapped[] = $people[(int) $id];
            }
        }

        return $mapped;
    }

    /**
     * @return list<int>
     */
    private function settle(User $eval, CarbonImmutable $split): array
    {
        $messages = CommsMessage::query()
            ->whereBelongsTo($eval)
            ->whereNull('deleted_at')
            ->where('occurred_at', '>=', $split)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        foreach ($messages as $message) {
            $this->buffer->add($message);
        }

        $this->buffer->due($eval, CarbonImmutable::now());

        $latencies = [];
        $events = CommsEvent::query()
            ->whereBelongsTo($eval)
            ->where('type', EventType::WindowSettled)
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $started = hrtime(true);
            $this->pipeline->process($event);
            $latencies[] = (int) round((hrtime(true) - $started) / 1_000_000);
        }

        return $latencies;
    }

    /**
     * @param  list<int>  $latencies
     * @return array<string, mixed>
     */
    private function report(
        User $source,
        User $eval,
        CarbonImmutable $start,
        CarbonImmutable $split,
        CarbonImmutable $end,
        int $early,
        int $late,
        array $latencies,
    ): array {
        $usage = AiUsage::query()->whereBelongsTo($eval)->whereNotNull('window_id')->get();
        $tokens = (int) $usage->sum(fn (AiUsage $row): int => (int) $row->input_tokens + (int) $row->output_tokens);
        $cost = (float) $usage->sum(fn (AiUsage $row): float => (float) ($row->cost ?? 0));
        $windows = count($latencies);
        $needs = $this->needsUser($eval, $split);
        $critic = $this->critic($eval);
        $decisions = CommsLinkDecision::query()->whereBelongsTo($eval);
        $decisionCount = (clone $decisions)->count();
        $uncertain = (clone $decisions)->where('certainty', 'uncertain')->count();

        return [
            'user_id' => $source->id,
            'eval_user_id' => $eval->id,
            'kind' => 'backfill',
            'critic_provider' => (string) config('comms.tasks.critic.provider'),
            'passed' => null,
            'window' => [
                'start' => $start->toIso8601String(),
                'split' => $split->toIso8601String(),
                'end' => $end->toIso8601String(),
            ],
            'cloned' => ['early' => $early, 'late' => $late],
            'prompt_versions' => $this->versions(),
            'retrieval' => $this->retrieval->measure($eval),
            'uncertain_share' => $decisionCount === 0 ? null : round($uncertain / $decisionCount, 4),
            'needs_user' => $needs,
            'critic' => $critic,
            'tokens_per_window' => $windows === 0 ? null : round($tokens / $windows, 4),
            'cost_per_window' => $windows === 0 ? null : round($cost / $windows, 4),
            'latency_ms' => [
                'p50' => $this->percentile($latencies, 50),
                'p95' => $this->percentile($latencies, 95),
                'windows' => $windows,
            ],
        ];
    }

    /**
     * @return array{segment: int, link: int, extract: int, critic: int, topic_build: int, identity_resolve: int}
     */
    private function versions(): array
    {
        return [
            'segment' => (int) config('comms.tasks.segment.prompt'),
            'link' => (int) config('comms.tasks.link.prompt'),
            'extract' => (int) config('comms.tasks.extract.prompt'),
            'critic' => (int) config('comms.tasks.critic.prompt'),
            'topic_build' => (int) config('comms.tasks.topic_build.prompt'),
            'identity_resolve' => (int) config('comms.tasks.identity_resolve.prompt'),
        ];
    }

    /**
     * @return array{tp: int, fp: int, fn: int, tn: int, precision: ?float, recall: ?float}
     */
    private function needsUser(User $eval, CarbonImmutable $split): array
    {
        $labels = CommsGoldenLabel::query()
            ->whereBelongsTo($eval)
            ->where('kind', 'weak_label')
            ->whereIn('label', ['acted', 'ignored'])
            ->orderBy('id')
            ->get();
        $counts = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'tn' => 0];

        foreach ($labels as $label) {
            $message = CommsMessage::query()->find($label->message_id);

            if (! $message instanceof CommsMessage || $message->occurred_at->lt($split)) {
                continue;
            }

            $predicted = $this->predicted($eval, $message);
            $actual = $label->label === 'acted';

            if ($predicted && $actual) {
                $counts['tp']++;
            } elseif ($predicted) {
                $counts['fp']++;
            } elseif ($actual) {
                $counts['fn']++;
            } else {
                $counts['tn']++;
            }
        }

        return [
            ...$counts,
            'precision' => $this->rate($counts['tp'], $counts['tp'] + $counts['fp']),
            'recall' => $this->rate($counts['tp'], $counts['tp'] + $counts['fn']),
        ];
    }

    private function predicted(User $eval, CommsMessage $message): bool
    {
        $selfId = CommsPerson::query()->whereBelongsTo($eval)->where('relation', Relation::Self)->value('id');
        $intentIds = CommsConversationLink::query()
            ->whereBelongsTo($eval)
            ->where('message_id', $message->id)
            ->pluck('intent_id');

        foreach ($intentIds as $intentId) {
            $intent = CommsIntent::query()->find($intentId);

            if (! $intent instanceof CommsIntent) {
                continue;
            }

            if ($intent->possible_ask) {
                return true;
            }

            if ($selfId !== null && $intent->participants()->where('role', 'responder')->where('person_id', $selfId)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{refuted: int, checked: int, rate: ?float}
     */
    private function critic(User $eval): array
    {
        $rows = AiUsage::query()
            ->whereBelongsTo($eval)
            ->where('task', 'critic')
            ->where('status', 'ok')
            ->orderBy('id')
            ->get();
        $checked = 0;
        $refuted = 0;

        foreach ($rows as $row) {
            if (! is_array($row->output)) {
                continue;
            }

            $texts = CommsMessage::query()
                ->where('window_id', $row->window_id)
                ->orderBy('id')
                ->pluck('text')
                ->map(fn (mixed $text): string => (string) $text)
                ->all();
            $checked++;

            if (CriticVerdict::read($row->output, $texts)->refuted) {
                $refuted++;
            }
        }

        return [
            'refuted' => $refuted,
            'checked' => $checked,
            'rate' => $this->rate($refuted, $checked),
        ];
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }

    /**
     * @param  list<int>  $values
     */
    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return (int) $values[max(0, min(count($values) - 1, $index))];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function write(CarbonImmutable $end, array $report): string
    {
        $configured = config('comms.evaluation.directory');
        $directory = is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('comms/evals');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/'.$end->toDateString().'.json';
        $suffix = 2;

        while (File::exists($path)) {
            $path = $directory.'/'.$end->toDateString().'-'.$suffix.'.json';
            $suffix++;
        }

        File::put($path, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }
}
