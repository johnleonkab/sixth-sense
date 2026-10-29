<?php

namespace App\Knowledge;

use App\Connectors\GitHub\GithubApi;
use App\Connectors\GitHub\GithubContents;
use App\Connectors\Issues\Issue;
use App\Connectors\Issues\IssueComment;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Wiki\Wiki;
use App\Models\GithubRepository;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Sense\DocumentRules;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Keeps the local copy of the work archive, the Jira issues the person takes
 * part in and the Notion pages they shared. Only read methods: it never
 * changes those services.
 *
 * Each run lists what changed (cheap) and then reads the content of a few
 * documents, so a large first copy spreads over several runs instead of
 * holding a worker for minutes. A full run also drops what disappeared.
 */
class KnowledgeSync
{
    /** Documents whose content is read per source and run. */
    private const CONTENT_PER_RUN = 40;

    /** What is kept of one document; enough to answer from it. */
    private const MAX_CHARACTERS = 60000;

    /** Archive files worth reading as text. */
    private const TEXT_EXTENSIONS = ['md', 'markdown', 'txt', 'yml', 'yaml', 'csv'];

    public function __construct(
        private GithubApi $github,
        private GithubContents $contents,
        private IssueTracker $issues,
        private Wiki $wiki,
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
     * Read one document again now, so it can be opened before the next run
     * gets to it. False when the service no longer has it.
     */
    public function refresh(KnowledgeDocument $document): bool
    {
        $read = match ($document->source) {
            KnowledgeSource::Archive => $this->archiveFile($document),
            KnowledgeSource::Jira => $this->jiraIssue($document),
            KnowledgeSource::Notion => $this->notionPage($document),
        };

        if ($read === null) {
            $document->delete();

            return false;
        }

        $document->update([
            'title' => mb_substr($read['title'] ?? $document->title, 0, 500),
            'content' => mb_substr($read['content'], 0, self::MAX_CHARACTERS),
            'indexed_at' => now(),
        ]);

        return true;
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
     * Save what the listing says. A new version clears the content so the
     * next read copies it again.
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
     * Read the content of the documents that are new or changed, oldest
     * listed first.
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
