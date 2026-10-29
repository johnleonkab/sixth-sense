<?php

namespace App\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Keeps a short summary only of the documents that matter most: the most
 * opened lately and the most recently changed. The rest of the knowledge
 * base is an index; their summaries are dropped when they stop mattering.
 */
class KnowledgeSummaries
{
    /** Documents with a summary per person, at most. */
    public const KEPT = 50;

    /** Of those, the most opened in this window. */
    private const MOST_VIEWED = 30;

    private const VIEWED_DAYS = 30;

    /** And the most recently changed in this window. */
    private const RECENT_DAYS = 14;

    /** New summaries written per run, so the cost stays small. */
    private const PER_RUN = 10;

    public function __construct(
        private KnowledgeSync $sync,
        private DocumentSummarizer $summarizer,
    ) {}

    /**
     * @return int Summaries written in this run.
     */
    public function refresh(User $user, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $chosen = $this->chosen($user, $now);

        $user->knowledgeDocuments()
            ->where('source', '!=', KnowledgeSource::Memory)
            ->whereNotNull('summary')
            ->whereNotIn('id', $chosen->pluck('id'))
            ->update(['summary' => null, 'summarized_version' => null]);

        $written = 0;

        foreach ($chosen as $document) {
            if ($written >= self::PER_RUN) {
                break;
            }

            if ($document->summary !== null && $document->summarized_version === $document->version) {
                continue;
            }

            try {
                $text = $this->sync->text($document);

                if ($text === null || trim($text) === '') {
                    continue;
                }

                $document->update([
                    'summary' => $this->summarizer->summarize($user, $document->title, $text),
                    'summarized_version' => $document->version,
                ]);
                $written++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $written;
    }

    /**
     * The most opened in the last month first, then the most recently changed.
     *
     * @return Collection<int, KnowledgeDocument>
     */
    private function chosen(User $user, CarbonImmutable $now): Collection
    {
        $documents = fn () => $user->knowledgeDocuments()->where('source', '!=', KnowledgeSource::Memory)->whereNotNull('indexed_at');

        $viewed = $documents()
            ->where('views', '>', 0)
            ->where('last_viewed_at', '>=', $now->subDays(self::VIEWED_DAYS))
            ->orderByDesc('views')
            ->orderByDesc('last_viewed_at')
            ->limit(self::MOST_VIEWED)
            ->get();

        $recent = $documents()
            ->where('source_updated_at', '>=', $now->subDays(self::RECENT_DAYS))
            ->whereNotIn('id', $viewed->pluck('id'))
            ->orderByDesc('source_updated_at')
            ->limit(self::KEPT - $viewed->count())
            ->get();

        return $viewed->concat($recent)->values();
    }
}
