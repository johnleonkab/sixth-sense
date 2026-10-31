<?php

namespace App\Alerts\Questions;

use App\Alerts\Actions\ClassifiedAction;
use App\Alerts\Replies\AlertBrief;
use App\Connectors\Issues\IssueSummary;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Wiki\Wiki;
use App\Knowledge\KnowledgeBase;
use App\Knowledge\KnowledgeSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Looks for the answer to a question someone asked the person, before the
 * reply is drafted. The state of a feature: the Jira index for candidates,
 * then a closed lookup of the issue that fits (its key, status and when it
 * was last touched). Documentation, projects and context: the archive,
 * Notion and the person's memory. Several plausible candidates are listed for
 * the person to choose; nothing found is said, never guessed.
 */
class QuestionResearch
{
    private const CANDIDATES = 3;

    public function __construct(
        private KnowledgeBase $knowledge,
        private IssueTracker $issues,
        private Wiki $wiki,
    ) {}

    public function answer(User $user, ClassifiedAction $question, AlertBrief $brief): QuestionAnswer
    {
        $terms = $this->terms($question, $brief);
        $query = implode(' ', $terms);

        if ($query === '') {
            return $this->nothing();
        }

        $candidates = ($question->data['about'] ?? 'other') === 'feature'
            ? $this->issueCandidates($user, $query)
            : $this->documentCandidates($user, $query);
        $plausible = $this->plausible($candidates, $terms);

        return match (count($plausible)) {
            0 => $this->nothing(),
            1 => $this->found($user, $plausible[0]),
            default => new QuestionAnswer(QuestionAnswer::SEVERAL, $plausible, 'Hay varios candidatos que podrían responder la pregunta: '
                .implode('; ', array_map(fn (array $source): string => $source['title'], $plausible)).'. No elijas uno: el borrador espera a que él diga cuál.'),
        };
    }

    /**
     * The answer from the candidate the person picked among several.
     *
     * @param  array{kind: string, ref: string, title: string, url: ?string, updated_at: ?string}  $source
     */
    public function from(User $user, array $source): QuestionAnswer
    {
        return $this->found($user, $source);
    }

    /**
     * @return list<array{kind: string, ref: string, title: string, url: ?string, updated_at: ?string, summary?: ?string}>
     */
    private function issueCandidates(User $user, string $query): array
    {
        $indexed = $this->safely(fn (): array => $this->knowledge->search($user, $query, [KnowledgeSource::Jira], 6));
        $candidates = array_map(fn (array $document): array => ['kind' => 'jira', 'ref' => $document['ref'], 'title' => $document['title'], 'url' => $document['url'], 'updated_at' => $document['updated_at']], $indexed);

        // Not indexed yet: the tracker's own search.
        if ($candidates === []) {
            $candidates = array_map(fn (IssueSummary $issue): array => [
                'kind' => 'jira', 'ref' => $issue->ref, 'title' => "{$issue->key} {$issue->summary}", 'url' => $issue->url, 'updated_at' => $issue->updatedAt?->toIso8601String(),
            ], $this->safely(fn (): array => $this->issues->search($user, null, $query, 6)));
        }

        return $candidates;
    }

    /**
     * @return list<array{kind: string, ref: string, title: string, url: ?string, updated_at: ?string, summary?: ?string}>
     */
    private function documentCandidates(User $user, string $query): array
    {
        $indexed = array_map(fn (array $document): array => [
            'kind' => Str::lower(Str::ascii($document['source'])), 'ref' => $document['ref'], 'title' => $document['title'], 'url' => $document['url'], 'updated_at' => $document['updated_at'], 'summary' => $document['summary'],
        ], $this->safely(fn (): array => $this->knowledge->search($user, $query, [KnowledgeSource::Archive, KnowledgeSource::Notion, KnowledgeSource::Memory, KnowledgeSource::Meeting], 6)));
        $known = array_column($indexed, 'ref');
        $pages = array_map(fn ($page): array => ['kind' => 'notion', 'ref' => $page->ref, 'title' => $page->title, 'url' => $page->url, 'updated_at' => $page->editedAt?->toIso8601String()], $this->safely(fn (): array => $this->wiki->search($user, $query, 4)));

        return [...$indexed, ...array_values(array_filter($pages, fn (array $page): bool => ! in_array($page['ref'], $known, true)))];
    }

    /**
     * The candidates whose title fits the question best: one when it stands
     * out, a few when they tie, none when nothing shares a word with it.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $terms
     * @return list<array<string, mixed>>
     */
    private function plausible(array $candidates, array $terms): array
    {
        $scored = array_map(fn (array $candidate): array => [$candidate, count(array_filter($terms, fn (string $term): bool => str_contains(self::plain($candidate['title']), $term)))], $candidates);
        $best = max([0, ...array_column($scored, 1)]);

        if ($best === 0) {
            return [];
        }

        $top = array_values(array_map(fn (array $pair): array => $pair[0], array_filter($scored, fn (array $pair): bool => $pair[1] === $best)));

        return array_slice($top, 0, self::CANDIDATES);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function found(User $user, array $source): QuestionAnswer
    {
        $updated = filled($source['updated_at'] ?? null) ? CarbonImmutable::parse($source['updated_at'])->setTimezone(config('agenda.timezone'))->format('d/m/Y') : null;

        if ($source['kind'] === 'jira') {
            $issue = $this->safely(fn (): array => array_filter([$this->issues->issue($user, $source['ref'])]))[0] ?? null;

            if ($issue !== null) {
                $source = [
                    ...$source,
                    'key' => $issue->summary->key,
                    'title' => "{$issue->summary->key} {$issue->summary->summary}",
                    'status' => $issue->summary->status,
                    'url' => $issue->summary->url ?? $source['url'],
                    'updated_at' => $issue->summary->updatedAt?->toIso8601String() ?? $source['updated_at'],
                ];
                $updated = $issue->summary->updatedAt?->setTimezone(config('agenda.timezone'))->format('d/m/Y') ?? $updated;
                $last = collect($issue->comments)->last();

                return new QuestionAnswer(QuestionAnswer::FOUND, [$source], implode("\n", array_filter([
                    "Respuesta encontrada en Jira: {$source['title']}, estado «{$source['status']}»".($updated ? ", actualizado el {$updated}" : '').'.',
                    $issue->summary->assignee ? "Lo lleva {$issue->summary->assignee}." : null,
                    filled($issue->descriptionText) ? 'Descripción: '.Str::limit($issue->descriptionText, 600) : null,
                    $last ? "Último comentario ({$last->author}): ".Str::limit($last->text, 400) : null,
                    'La respuesta cita la clave del issue, su estado y la fecha de la última actualización.',
                ])));
            }
        }

        return new QuestionAnswer(QuestionAnswer::FOUND, [$source], implode("\n", array_filter([
            "Respuesta encontrada en {$source['title']}".($source['url'] ? " ({$source['url']})" : '').($updated ? ", actualizado el {$updated}" : '').'.',
            filled($source['summary'] ?? null) ? 'Dice: '.Str::limit($source['summary'], 800) : null,
            'La respuesta dice de dónde sale y enlaza la fuente.',
        ])));
    }

    private function nothing(): QuestionAnswer
    {
        return new QuestionAnswer(QuestionAnswer::NONE, [], 'No he encontrado la respuesta en Jira, el archivo ni Notion. No la inventes: el borrador dice que lo mira y responde en cuanto lo sepa.');
    }

    /**
     * The words to look for: the classifier's, or the question's own.
     *
     * @return list<string>
     */
    private function terms(ClassifiedAction $question, AlertBrief $brief): array
    {
        $words = $question->data['terms'] ?? [];

        if ($words === []) {
            $words = preg_split('/\s+/', $brief->subject.' '.$brief->text) ?: [];
        }

        $stop = ['como', 'esta', 'esto', 'para', 'sobre', 'donde', 'cuando', 'sabes', 'tienes', 'puedes', 'estado', 'feature', 'sale', 'este', 'that', 'what', 'with'];
        $terms = array_filter(array_map(fn (string $word): string => self::plain($word), $words), fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, $stop, true));

        return array_slice(array_values(array_unique($terms)), 0, 6);
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9 ]+/', ' ', Str::lower(Str::ascii($text))));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $search
     * @return T|array{}
     */
    private function safely(callable $search): mixed
    {
        try {
            return $search();
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
