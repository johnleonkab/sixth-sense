<?php

namespace App\Comms\Models;

use App\Comms\Events\EventLog;
use App\Comms\Events\EventType;
use App\Comms\Events\Source;
use App\Models\AiUsage;
use App\Models\Comms\CommsEvent;
use App\Models\User;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * The only model entry for the communications pipeline. Callers name a task.
 * A bad schema is retried once, then escalated one tier with the failed output
 * as a hint. The top tier's second failure records a question. A spent budget
 * records a no-draft marker and does not call a model.
 */
class ModelRouter
{
    public function __construct(
        private CompletesTasks $models,
        private EventLog $log,
        private Budget $budget,
        private SchemaCheck $schemas,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<int>  $eventIds
     */
    public function ask(User $user, string $task, string $input, array $schema, array $eventIds = [], bool $sensitive = false, int $runTokens = 0, ?int $runIndex = null, ?int $windowId = null): ModelAnswer
    {
        $definition = config("comms.tasks.{$task}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown communications task [{$task}].");
        }

        $version = (int) $definition['prompt'];
        $provider = (string) $definition['provider'];
        $key = $this->decisionKey($task, $eventIds, $version, $runIndex, $input);
        $stored = $this->storedDecision($user, $key);

        if ($stored !== null) {
            $output = $stored->normalized['output'] ?? null;

            if (is_array($output)) {
                return ModelAnswer::accepted($output, $stored);
            }
        }

        if ($this->budget->exhausted($user, $eventIds, $runTokens)) {
            return $this->marker($user, $task, $provider, $version, $eventIds, EventType::NoDraft, 'budget', 'budget', $runIndex, $windowId);
        }

        $system = $this->prompt($task, $version);
        $tier = (string) $definition['tier'];
        $fallback = isset($definition['fallback']) ? (string) $definition['fallback'] : null;
        $hint = null;
        $retried = false;

        while (true) {
            $prompt = $hint === null
                ? $input
                : $input."\n\nPrevious attempt:\n".json_encode($hint, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            try {
                $completion = $this->attempt($provider, $fallback, $tier, $system, $prompt, $schema);
            } catch (Throwable $exception) {
                $this->record($user, $task, $tier, $provider, $version, $eventIds, null, 'unavailable', $exception->getMessage(), 0, 0, 0, null, 'unsent', $runIndex, $windowId);

                [$tier, $retried, $hint] = $this->afterFailure($tier, $retried, $hint, ['error' => $exception->getMessage()]);

                if ($tier === null) {
                    return $this->marker($user, $task, $provider, $version, $eventIds, EventType::Question, 'schema', null, $runIndex, $windowId);
                }

                continue;
            }

            $valid = $this->schemas->passes($schema, $completion->body);

            $this->record(
                $user,
                $task,
                $tier,
                $provider,
                $version,
                $eventIds,
                $sensitive ? null : $completion->body,
                $valid ? 'ok' : 'invalid',
                null,
                $completion->inputTokens,
                $completion->outputTokens,
                $completion->latencyMs,
                $completion->cost,
                $completion->model,
                $runIndex,
                $windowId,
            );

            if (! $valid) {
                [$tier, $retried, $hint] = $this->afterFailure($tier, $retried, $hint, $completion->body);

                if ($tier === null) {
                    return $this->marker($user, $task, $provider, $version, $eventIds, EventType::Question, 'schema', null, $runIndex, $windowId);
                }

                continue;
            }

            if ($this->lowConfidence($task, $completion->body) && $this->nextTier($tier) !== null) {
                $hint = $completion->body;
                $tier = $this->nextTier($tier);
                $retried = false;

                continue;
            }

            $event = $this->log->append(
                user: $user,
                type: EventType::ModelDecided,
                source: Source::Model,
                externalId: $key,
                payload: null,
                normalized: [
                    'task' => $task,
                    'tier' => $tier,
                    'prompt_version' => $version,
                    'output' => $completion->body,
                    'event_ids' => $eventIds,
                ],
                causation: $this->causation($user, $eventIds),
                occurredAt: now(),
            );

            return ModelAnswer::accepted($completion->body, $event);
        }
    }

    /**
     * One stored decision per run. The closure builds that run's input, so a
     * caller can shuffle candidates without changing the rest of the prompt.
     *
     * @param  string  $input  Stable text the caller closes over. Each run sends what the closure returns.
     * @param  array<string, mixed>  $schema
     * @param  list<int>  $eventIds
     * @param  Closure(int): string  $inputForRun
     * @return list<ModelAnswer>
     */
    public function runs(User $user, string $task, string $input, array $schema, array $eventIds, ?int $windowId, Closure $inputForRun): array
    {
        $definition = config("comms.tasks.{$task}");

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown communications task [{$task}].");
        }

        $count = (int) ($definition['runs'] ?? 1);
        $answers = [];

        for ($runIndex = 0; $runIndex < max(1, $count); $runIndex++) {
            $answers[] = $this->ask(
                user: $user,
                task: $task,
                input: $inputForRun($runIndex),
                schema: $schema,
                eventIds: $eventIds,
                windowId: $windowId,
                runIndex: $runIndex,
            );
        }

        return $answers;
    }

    /**
     * @param  list<int>  $eventIds
     */
    private function decisionKey(string $task, array $eventIds, int $version, ?int $runIndex, string $input): string
    {
        $encoded = json_encode([$eventIds, $version, $runIndex, sha1($input)], JSON_THROW_ON_ERROR);

        return $task.':'.sha1($encoded);
    }

    private function storedDecision(User $user, string $key): ?CommsEvent
    {
        return CommsEvent::query()
            ->whereBelongsTo($user)
            ->where('type', EventType::ModelDecided)
            ->where('source', Source::Model)
            ->where('external_id', $key)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function attempt(string $provider, ?string $fallback, string $tier, string $system, string $input, array $schema): ModelCompletion
    {
        try {
            return $this->models->complete($provider, $tier, $system, $input, $schema);
        } catch (Throwable $exception) {
            if ($fallback === null || $fallback === $provider) {
                throw $exception;
            }

            return $this->models->complete($fallback, $tier, $system, $input, $schema);
        }
    }

    /**
     * Retry the same tier once. The second failure escalates, carrying the
     * failed output. The top tier's second failure stops the branch.
     *
     * @param  array<string, mixed>|null  $hint
     * @param  array<string, mixed>  $failed
     * @return array{0: ?string, 1: bool, 2: ?array<string, mixed>}
     */
    private function afterFailure(string $tier, bool $retried, ?array $hint, array $failed): array
    {
        if (! $retried) {
            return [$tier, true, $hint];
        }

        $next = $this->nextTier($tier);

        if ($next === null) {
            return [null, true, null];
        }

        return [$next, false, $failed];
    }

    private function nextTier(string $tier): ?string
    {
        return match ($tier) {
            'small' => 'mid',
            'mid' => 'large',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function lowConfidence(string $task, array $output): bool
    {
        $tasks = config('comms.escalate_on_confidence', []);

        if (! is_array($tasks) || ! in_array($task, $tasks, true)) {
            return false;
        }

        if (! isset($output['confidence']) || ! is_numeric($output['confidence'])) {
            return false;
        }

        return (float) $output['confidence'] < (float) config('comms.confidence_floor');
    }

    private function prompt(string $task, int $version): string
    {
        $path = resource_path("prompts/comms/{$task}/v{$version}.md");

        if (! is_file($path)) {
            throw new InvalidArgumentException("Missing prompt {$task} v{$version}.");
        }

        return trim((string) file_get_contents($path));
    }

    /**
     * @param  list<int>  $eventIds
     * @param  array<string, mixed>|null  $output
     */
    private function record(
        User $user,
        string $task,
        string $tier,
        string $provider,
        int $version,
        array $eventIds,
        ?array $output,
        string $status,
        ?string $error,
        int $inputTokens,
        int $outputTokens,
        int $latencyMs,
        ?float $cost,
        string $model = 'unsent',
        ?int $runIndex = null,
        ?int $windowId = null,
    ): void {
        AiUsage::query()->create([
            'user_id' => $user->id,
            'task' => $task,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            'cost' => $cost,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'tier' => $tier,
            'prompt_version' => $version,
            'event_ids' => $eventIds,
            'output' => $output,
            'run_index' => $runIndex,
            'window_id' => $windowId,
        ]);
    }

    /**
     * @param  list<int>  $eventIds
     */
    private function marker(User $user, string $task, string $provider, int $version, array $eventIds, EventType $type, string $reason, ?string $usageStatus, ?int $runIndex = null, ?int $windowId = null): ModelAnswer
    {
        if ($usageStatus !== null) {
            $this->record($user, $task, (string) config("comms.tasks.{$task}.tier"), $provider, $version, $eventIds, null, $usageStatus, null, 0, 0, 0, null, 'unsent', $runIndex, $windowId);
        }

        $event = $this->log->append(
            user: $user,
            type: $type,
            source: Source::Model,
            externalId: null,
            payload: null,
            normalized: [
                'task' => $task,
                'reason' => $reason,
                'event_ids' => $eventIds,
            ],
            causation: $this->causation($user, $eventIds),
            occurredAt: now(),
        );

        return $type === EventType::NoDraft
            ? ModelAnswer::noDraft($event)
            : ModelAnswer::question($event);
    }

    /**
     * @param  list<int>  $eventIds
     */
    private function causation(User $user, array $eventIds): ?CommsEvent
    {
        $id = $eventIds[0] ?? null;

        if (! is_int($id)) {
            return null;
        }

        return CommsEvent::query()->whereBelongsTo($user)->find($id);
    }
}
