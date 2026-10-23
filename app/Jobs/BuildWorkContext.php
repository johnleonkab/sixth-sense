<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WorkContext;
use App\Sense\PublishContext;
use App\WorkContext\ContextSummarizer;
use App\WorkContext\WorkActivityCollector;
use App\WorkContext\WorkContextStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Reads the person's recent activity and writes what they are working on,
 * for the voice assistant. Slack, Gmail, Drive, Notion and Jira stay
 * read-only. When the work archive allows code updates, the same digest
 * refreshes now.md and the writing and review agents. When it fails, the
 * previous context is kept until it gets too old.
 */
class BuildWorkContext implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public User $user) {}

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(WorkActivityCollector $collector, ContextSummarizer $summarizer, PublishContext $archive): void
    {
        $context = $this->context();

        if (! $context->enabled) {
            return;
        }

        $context->update(['status' => WorkContextStatus::Running, 'started_at' => now(), 'error' => null]);

        $activity = $collector->collect($this->user, CarbonImmutable::now());

        if ($activity->isEmpty()) {
            throw new RuntimeException($activity->failedSources === []
                ? 'No he encontrado actividad reciente en tus servicios conectados.'
                : 'No he podido leer '.implode(', ', $activity->failedSources).'.');
        }

        $digest = $summarizer->summarize($this->user, $activity);

        $context->update([
            'status' => WorkContextStatus::Ready,
            'summary' => $digest->summary,
            'digest' => $digest->toArray(),
            'sources' => [...$activity->counts(), 'failed' => $activity->failedSources],
            'generated_at' => now(),
        ]);

        try {
            $archive->publish($this->user, $digest);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Also called when the job times out.
     */
    public function failed(?Throwable $exception): void
    {
        $this->context()->update([
            'status' => WorkContextStatus::Failed,
            'error' => Str::limit($exception?->getMessage() ?: 'Ha tardado demasiado.', 300),
        ]);
    }

    private function context(): WorkContext
    {
        return $this->user->workContext()->firstOrCreate();
    }
}
