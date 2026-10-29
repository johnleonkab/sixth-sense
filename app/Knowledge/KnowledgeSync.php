<?php

namespace App\Knowledge;

use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearch;
use App\Connectors\GitHub\GithubApi;
use App\Connectors\GitHub\GithubContents;
use App\Connectors\Issues\Issue;
use App\Connectors\Issues\IssueComment;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Wiki\Wiki;
use App\Knowledge\Gaps\GapStatus;
use App\Models\GithubRepository;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeGap;
use App\Models\User;
use App\Models\WingmanMeeting;
use App\Sense\DocumentRules;
use App\Wingman\Notes\MeetingNotes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps the index of the work archive, the Jira issues the person takes part
 * in, the Notion pages they shared, the notes and transcripts of their
 * meetings, and what they answered when asked. Only read methods: it never
 * changes those services.
 *
 * It keeps no copy of a document: only its title, link and distinct words.
 * The text is read live when someone needs it (text()).
 *
 * Each run lists what changed (cheap) and then indexes a few documents, so a
 * large first index spreads over several runs instead of holding a worker for
 * minutes. A full run also drops what disappeared.
 */
class KnowledgeSync
{
    /** Documents whose content is read per source and run. */
    private const CONTENT_PER_RUN = 40;

    /** How far back meeting notes and transcripts are looked for at first. */
    private const MEETING_DAYS = 30;

    /**
     * Google Meet saves Gemini's notes and the transcript as Docs with these
     * words in the title, in English or Spanish depending on the account.
     */
    private const MEETING_TITLES = ['Notes by Gemini', 'Notas de Gemini', 'Transcript', 'Transcripción'];

    /** Archive files worth reading as text. */
    private const TEXT_EXTENSIONS = ['md', 'markdown', 'txt', 'yml', 'yaml', 'csv'];

    public function __construct(
        private GithubApi $github,
        private GithubContents $contents,
        private IssueTracker $issues,
        private Wiki $wiki,
        private Documents $documents,
    ) {}

    /**
     * @return array<string, int> Documents kept per source after the run.
     */
    public function sync(User $user, bool $full = false): array
    {
        $counts = [];

        foreach ([
            KnowledgeSource::Archive->value => fn () => $this->archive($user),
            KnowledgeSource::Jira->value => fn () => $this->jira($user, $full),
            KnowledgeSource::Notion->value => fn () => $this->notion($user, $full),
            KnowledgeSource::Meeting->value => fn () => $this->meetings($user),
            KnowledgeSource::Memory->value => fn () => $this->syncMemory($user),
        ] as $source => $run) {
            try {
                $run();
            } catch (Throwable $exception) {
                report($exception);
            }

            $counts[$source] = $user->knowledgeDocuments()->where('source', $source)->count();
        }

        return $counts;
    }

    /**
     * Index one document again now: its title and distinct words, read live.
     * False when the service no longer has it.
     */
    public function refresh(KnowledgeDocument $document): bool
    {
        $read = $this->read($document);

        if ($read === null) {
            $document->delete();

            return false;
        }

        $document->update([
            'title' => mb_substr($read['title'] ?? $document->title, 0, 500),
            'terms' => KnowledgeTerms::of(($read['title'] ?? $document->title)."\n".$read['content']),
            'summary' => $document->source === KnowledgeSource::Memory ? $read['content'] : $document->summary,
            'indexed_at' => now(),
        ]);

        return true;
    }

    /**
     * The document's text, read live from its service and not kept. Null
     * when the service no longer has it.
     */
    public function text(KnowledgeDocument $document): ?string
    {
        return $this->read($document)['content'] ?? null;
    }

    /**
     * @return array{title?: string, content: string}|null
     */
    private function read(KnowledgeDocument $document): ?array
    {
        return match ($document->source) {
            KnowledgeSource::Archive => $this->archiveFile($document),
            KnowledgeSource::Jira => $this->jiraIssue($document),
            KnowledgeSource::Notion => $this->notionPage($document),
            KnowledgeSource::Meeting => $this->meeting($document),
            KnowledgeSource::Memory => $this->answer($document),
        };
    }

    /**
     * The archive is listed whole every time: one call returns every file
     * with the sha of its contents.
     */
    private function archive(User $user): void
    {
        $repository = $user->workRepository();

        if ($repository === null) {
            $user->knowledgeDocuments()->where('source', KnowledgeSource::Archive)->delete();

            return;
        }

        $now = CarbonImmutable::now();
        $listed = [];

        foreach ($this->github->blobs($repository) as $path => $sha) {
            if (! in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true)) {
                continue;
            }

            $listed[] = $path;
            $this->remember($user, KnowledgeSource::Archive, $path, $path, $this->githubUrl($repository, $path), $sha, null, $now);
        }

        $stale = $user->knowledgeDocuments()->where('source', KnowledgeSource::Archive);

        if ($listed === []) {
            $stale->delete();
        } else {
            $stale->whereNotIn('ref', $listed)->delete();
        }

        $this->fill($user, KnowledgeSource::Archive);
    }

    /**
     * Only issues changed since the newest one already copied; a full run
     * starts again from a year back and forgets older ones.
     */
    private function jira(User $user, bool $full): void
    {
        $documents = $user->knowledgeDocuments()->where('source', KnowledgeSource::Jira);
        $since = $full ? null : $documents->clone()->max('source_updated_at');
        $now = CarbonImmutable::now();

        foreach ($this->issues->involved($user, $since === null ? null : CarbonImmutable::parse($since), 200) as $issue) {
            $this->remember(
                $user,
                KnowledgeSource::Jira,
                $issue->ref,
                "{$issue->key} {$issue->summary}",
                $issue->url,
                $issue->updatedAt?->toIso8601String(),
                $issue->updatedAt,
                $now,
            );
        }

        if ($full) {
            $documents->clone()->where('source_updated_at', '<', $now->subYear())->delete();
        }

        $this->fill($user, KnowledgeSource::Jira);
    }

    /**
     * Only pages edited since the newest one already copied. A full run lists
     * every shared page and forgets the ones no longer shared.
     */
    private function notion(User $user, bool $full): void
    {
        $documents = $user->knowledgeDocuments()->where('source', KnowledgeSource::Notion);
        $since = $full ? null : $documents->clone()->max('source_updated_at');
        $now = CarbonImmutable::now();
        $limit = $full ? 2000 : 500;
        $pages = $this->wiki->edited($user, $since === null ? null : CarbonImmutable::parse($since), $limit);

        foreach ($pages as $page) {
            $this->remember(
                $user,
                KnowledgeSource::Notion,
                $page->ref,
                $page->title,
                $page->url,
                $page->editedAt?->toIso8601String(),
                $page->editedAt,
                $now,
            );
        }

        // An empty or cut listing may be a failure, not pages unshared.
        if ($full && $pages !== [] && count($pages) < $limit) {
            $documents->clone()->where('seen_at', '<', $now)->delete();
        }

        $this->fill($user, KnowledgeSource::Notion);
    }

    /**
     * Gemini's notes and the transcripts in Drive, and the notes Wingman took,
     * changed since the newest one already copied.
     */
    private function meetings(User $user): void
    {
        $documents = $user->knowledgeDocuments()->where('source', KnowledgeSource::Meeting);
        $newest = $documents->clone()->max('source_updated_at');
        $since = $newest === null ? CarbonImmutable::now()->subDays(self::MEETING_DAYS) : CarbonImmutable::parse($newest)->subHour();
        $now = CarbonImmutable::now();

        foreach (self::MEETING_TITLES as $title) {
            try {
                $found = $this->documents->search($user, new DocumentSearch(name: $title, modifiedAfter: $since, limit: 50))->documents;
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($found as $document) {
                if (preg_match('/notes by gemini|notas de gemini|transcript|transcripci[oó]n/iu', $document->title) !== 1) {
                    continue;
                }

                $this->remember($user, KnowledgeSource::Meeting, $document->ref, $document->title, $document->url, $document->modifiedAt->toIso8601String(), $document->modifiedAt, $now);
            }
        }

        $user->wingmanMeetings()
            ->whereNotNull('notes')
            ->where('notes_updated_at', '>=', $since)
            ->get()
            ->each(fn (WingmanMeeting $meeting) => $this->remember(
                $user,
                KnowledgeSource::Meeting,
                "wingman:{$meeting->id}",
                "{$meeting->title} · {$meeting->starts_at?->toDateString()} (notas de Wingman)",
                $meeting->meeting_url,
                $meeting->notes_updated_at?->toIso8601String(),
                $meeting->notes_updated_at === null ? null : CarbonImmutable::parse($meeting->notes_updated_at),
                $now,
            ));

        $this->fill($user, KnowledgeSource::Meeting);
    }

    /**
     * What the person answered when asked, copied straight from the questions:
     * nothing to read from a service. Called right after an answer too.
     */
    public function syncMemory(User $user): void
    {
        $answered = $user->knowledgeGaps()->where('status', GapStatus::Answered)->get();
        $now = CarbonImmutable::now();

        foreach ($answered as $gap) {
            $this->remember($user, KnowledgeSource::Memory, "gap:{$gap->id}", "{$gap->kind->label()}: {$gap->subject}", null, $gap->answered_at?->toIso8601String(), $gap->answered_at, $now);
        }

        $stale = $user->knowledgeDocuments()->where('source', KnowledgeSource::Memory);
        $kept = $answered->map(fn (KnowledgeGap $gap): string => "gap:{$gap->id}")->all();

        if ($kept === []) {
            $stale->delete();
        } else {
            $stale->whereNotIn('ref', $kept)->delete();
        }

        $this->fill($user, KnowledgeSource::Memory);
    }

    /**
     * Save what the listing says. A new version marks it to be indexed
     * again on the next read.
     */
    private function remember(User $user, KnowledgeSource $source, string $ref, string $title, ?string $url, ?string $version, ?CarbonImmutable $updatedAt, CarbonImmutable $now): void
    {
        $document = $user->knowledgeDocuments()->firstOrNew(['source' => $source, 'ref' => $ref]);
        $changed = ! $document->exists || $document->version !== $version;

        $document->fill([
            'title' => mb_substr($document->exists && $document->indexed_at !== null && $source === KnowledgeSource::Archive ? $document->title : $title, 0, 500),
            'url' => $url,
            'version' => $version,
            'source_updated_at' => $updatedAt,
            'seen_at' => $now,
        ]);

        if ($changed) {
            $document->indexed_at = null;
        }

        $document->save();
    }

    /**
     * Index the documents that are new or changed, oldest listed first.
     */
    private function fill(User $user, KnowledgeSource $source): void
    {
        $pending = $user->knowledgeDocuments()
            ->where('source', $source)
            ->whereNull('indexed_at')
            ->orderBy('id')
            ->limit(self::CONTENT_PER_RUN)
            ->get();

        foreach ($pending as $document) {
            try {
                $this->refresh($document);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * @return array{title?: string, content: string}|null
     */
    private function archiveFile(KnowledgeDocument $document): ?array
    {
        $repository = $document->user->workRepository();
        $file = $repository === null ? null : $this->contents->read($repository, $document->ref);

        if ($file === null) {
            return null;
        }

        return ['title' => $this->archiveTitle($document->ref, $file['content']), 'content' => $file['content']];
    }

    /**
     * @return array{title?: string, content: string}|null
     */
    private function jiraIssue(KnowledgeDocument $document): ?array
    {
        $issue = $this->issues->issue($document->user, $document->ref);

        return $issue === null ? null : ['title' => "{$issue->summary->key} {$issue->summary->summary}", 'content' => $this->issueText($issue)];
    }

    /**
     * @return array{title?: string, content: string}|null
     */
    private function notionPage(KnowledgeDocument $document): ?array
    {
        $page = $this->wiki->page($document->user, $document->ref);

        return $page === null ? null : ['title' => $page->title, 'content' => $page->markdown];
    }

    /**
     * @return array{title?: string, content: string}|null
     */
    private function meeting(KnowledgeDocument $document): ?array
    {
        if (str_starts_with($document->ref, 'wingman:')) {
            $meeting = $document->user->wingmanMeetings()->find((int) Str::after($document->ref, 'wingman:'));

            return $meeting === null ? null : ['content' => $this->wingmanText(MeetingNotes::fromArray($meeting->notes))];
        }

        $text = $this->documents->fullText($document->user, $document->ref);

        return $text === null ? null : ['content' => $text];
    }

    /**
     * @return array{title?: string, content: string}|null
     */
    private function answer(KnowledgeDocument $document): ?array
    {
        $gap = $document->user->knowledgeGaps()->where('status', GapStatus::Answered)->find((int) Str::after($document->ref, 'gap:'));

        return $gap === null ? null : ['content' => "Pregunta: {$gap->question}\nRespuesta: {$gap->answer}"];
    }

    private function wingmanText(MeetingNotes $notes): string
    {
        $lines = [$notes->summary];

        foreach ($notes->topics as $topic) {
            $lines[] = "\n{$topic['title']}:\n- ".implode("\n- ", $topic['points']);
        }

        foreach (['Decisiones' => $notes->decisions, 'Preguntas abiertas' => $notes->questions] as $label => $items) {
            if ($items !== []) {
                $lines[] = "\n{$label}:\n- ".implode("\n- ", $items);
            }
        }

        if ($notes->commitments !== []) {
            $lines[] = "\nCompromisos:\n- ".implode("\n- ", array_map(
                fn (array $commitment): string => "{$commitment['who']}: {$commitment['what']}".($commitment['when'] ? " ({$commitment['when']})" : ''),
                $notes->commitments,
            ));
        }

        return trim(implode("\n", $lines));
    }

    private function issueText(Issue $issue): string
    {
        $summary = $issue->summary;
        $lines = [
            implode(' · ', array_filter([$summary->key, $summary->type, $summary->status, $summary->assignee ? "asignado a {$summary->assignee}" : null, $issue->reporter ? "abierto por {$issue->reporter}" : null])),
        ];

        if ($issue->parent !== null) {
            $lines[] = "Dentro de {$issue->parent['key']} {$issue->parent['summary']}";
        }

        if (trim($issue->descriptionText) !== '') {
            $lines[] = "\nDescripción:\n".trim($issue->descriptionText);
        }

        if ($issue->comments !== []) {
            $lines[] = "\nComentarios:";

            foreach ($issue->comments as $comment) {
                /** @var IssueComment $comment */
                $lines[] = "- {$comment->author} ({$comment->createdAt->toDateString()}): ".trim($comment->text);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The `name` or `title` of its front matter, its first heading, or its path.
     */
    private function archiveTitle(string $path, string $contents): string
    {
        $document = DocumentRules::document($contents);

        foreach (['title', 'name'] as $key) {
            if (is_array($document) && is_string($document[$key] ?? null) && trim($document[$key]) !== '') {
                return trim($document[$key])." ({$path})";
            }
        }

        if (preg_match('/^#\s+(.+)$/m', $contents, $matches) === 1) {
            return trim($matches[1])." ({$path})";
        }

        return $path;
    }

    private function githubUrl(GithubRepository $repository, string $path): string
    {
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return "https://github.com/{$repository->full_name}/blob/{$repository->default_branch}/{$encoded}";
    }
}
