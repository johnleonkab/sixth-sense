<?php

namespace App\Comms\Context;

use App\Models\User;
use App\Models\WorkContext;
use App\Sense\PublishContext;
use App\WorkContext\ContextSummarizer;
use App\WorkContext\WorkActivityCollector;
use App\WorkContext\WorkContextStatus;
use App\WorkContext\WorkDigest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The nightly read: a model reads the person's recent Slack, Gmail, Drive,
 * Notion and Jira (read-only) and returns what they are working on. Each
 * piece becomes an inferred fact in one event, so replay needs no model. It
 * writes no open loops: those are intents. A fact it stops returning is not
 * deleted; it expires when it is not confirmed for 14 days. Then the work
 * archive is rewritten from the export pack.
 */
class ContextHarvest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [120];

    public int $timeout = 540;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    public function __construct(public User $user)
    {
        $this->onConnection('redis-long')->onQueue('context');
    }

    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    public function handle(WorkActivityCollector $collector, ContextSummarizer $summarizer, ContextWriter $writer, PublishContext $archive): void
    {
        $context = $this->context();

        if (! $context->enabled) {
            return;
        }

        $context->update(['status' => WorkContextStatus::Running, 'started_at' => now(), 'error' => null]);

        $now = CarbonImmutable::now();
        $activity = $collector->collect($this->user, $now);

        if ($activity->isEmpty()) {
            throw new RuntimeException($activity->failedSources === []
                ? 'No he encontrado actividad reciente en tus servicios conectados.'
                : 'No he podido leer '.implode(', ', $activity->failedSources).'.');
        }

        $digest = $summarizer->summarize($this->user, $activity);

        $writer->record($this->user, FactOrigin::Inferred, self::operations($digest, $now));

        $context->update([
            'status' => WorkContextStatus::Ready,
            'sources' => [...$activity->counts(), 'failed' => $activity->failedSources],
            'generated_at' => now(),
        ]);

        try {
            $archive->publish($this->user);
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

    /**
     * What one reading says, as fact operations. Each one carries the night
     * it came from, so the engine can hide them when the read is off.
     *
     * @return list<array<string, mixed>>
     */
    public static function operations(WorkDigest $digest, CarbonImmutable $at): array
    {
        $refs = ['harvest:'.$at->toDateString()];
        $slug = fn (string $text): string => Str::limit(Str::slug($text), 120, '') ?: 'x';
        $fact = fn (string $kind, string $subject, string $attribute, array $value): array => [
            'op' => 'set',
            'kind' => $kind,
            'subject' => $subject,
            'attribute' => $attribute,
            'value' => $value,
            'audiences' => [],
            'confidence' => 0.6,
            'refs' => $refs,
        ];
        $operations = [];

        if ($digest->summary !== '') {
            $operations[] = $fact('summary', 'user', 'summary', ['text' => $digest->summary]);
        }

        foreach ($digest->workstreams as $stream) {
            $operations[] = $fact('workstream', 'workstream:'.$slug($stream['name']), 'state', [
                'text' => $stream['name'].': '.$stream['what'],
                'name' => $stream['name'],
                'what' => $stream['what'],
                'why' => $stream['why'],
                'with' => $stream['with'],
                'where' => $stream['where'],
                'next' => $stream['next'],
            ]);
        }

        foreach ($digest->people as $person) {
            if ($person['relation'] !== '') {
                $operations[] = $fact('person', 'person:'.$slug($person['name']), 'note', ['text' => $person['relation'], 'name' => $person['name']]);
            }
        }

        foreach ($digest->contacts as $contact) {
            if ($contact['language'] !== '') {
                $operations[] = $fact('person', 'person:'.$slug($contact['name']), 'language', [
                    'value' => $contact['language'],
                    'name' => $contact['name'],
                    'where' => $contact['where'],
                    'note' => $contact['note'],
                ]);
            }
        }

        foreach (['slack' => $digest->writingSlack, 'gmail' => $digest->writingGmail] as $channel => $writing) {
            if ($writing !== '') {
                $operations[] = $fact('preference', 'channel:'.$channel, 'tone', ['text' => $writing]);
            }
        }

        foreach (['file' => $digest->hotFiles, 'page' => $digest->hotPages] as $prefix => $items) {
            foreach ($items as $item) {
                $operations[] = $fact('workstream', $prefix.':'.$slug($item['title']), 'hot', ['text' => $item['why'], 'title' => $item['title']]);
            }
        }

        foreach ($digest->jira as $update) {
            $operations[] = $fact('workstream', 'jira:'.$slug(Str::limit($update, 60, '')), 'moved', ['text' => $update]);
        }

        return $operations;
    }

    private function context(): WorkContext
    {
        return $this->user->workContext()->firstOrCreate();
    }
}
