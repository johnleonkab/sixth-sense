<?php

namespace App\WorkContext;

use App\Connectors\Chat\Chats;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearch;
use App\Connectors\Docs\DocumentSummary;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Mail\FoundMessage;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiPageSummary;
use App\Knowledge\KnowledgeSource;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads recent Slack, Gmail, Drive, Notion and Jira, and what changed in the
 * work archive. Only read methods: it
 * never changes those services. A source that fails is left out; the others
 * are still read.
 */
class WorkActivityCollector
{
    public function __construct(
        private Chats $chats,
        private Mailbox $mailbox,
        private Documents $documents,
        private Wiki $wiki,
        private IssueTracker $issues,
    ) {}

    public function collect(User $user, CarbonImmutable $now): WorkActivity
    {
        $failed = [];

        $read = function (string $source, callable $reader) use (&$failed): array {
            try {
                return $reader();
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $source;

                return [];
            }
        };

        return new WorkActivity(
            messages: $read('slack', fn (): array => $this->chats->activity($user, $now->subDays(config('work_context.days.slack')), config('work_context.limits.slack'))),
            emails: $read('gmail', fn (): array => $this->emails($user, $now->subDays(config('work_context.days.gmail')))),
            documents: $read('docs', fn (): array => $this->documents($user, $now->subDays(config('work_context.days.documents')))),
            pages: $read('notion', fn (): array => $this->pages($user)),
            failedSources: $failed,
            issues: $read('jira', fn (): array => $this->issues->search($user, null, null, (int) config('work_context.limits.issues'))),
            archive: $read('archive', fn (): array => $this->archive($user, $now->subDay())),
        );
    }

    /**
     * Work archive files indexed again since then (they changed), from the
     * knowledge base the nightly full sync just refreshed. The index keeps no
     * text: the summary when it has one.
     *
     * @return list<array{path: string, title: string, excerpt: string}>
     */
    private function archive(User $user, CarbonImmutable $since): array
    {
        return $user->knowledgeDocuments()
            ->where('source', KnowledgeSource::Archive)
            ->where('indexed_at', '>=', $since)
            ->orderByDesc('indexed_at')
            ->limit(20)
            ->get()
            ->map(fn (KnowledgeDocument $document): array => [
                'path' => $document->ref,
                'title' => $document->title,
                'excerpt' => Str::limit(trim((string) $document->summary), 400),
            ])
            ->all();
    }

    /**
     * What reached the primary inbox and what they sent, without bodies: the
     * snippet is enough to know what each email is about.
     *
     * @return list<MailMessage>
     */
    private function emails(User $user, CarbonImmutable $since): array
    {
        $received = $this->mailbox->search($user, new MailSearch(after: $since, folder: 'primary', limit: config('work_context.limits.gmail_inbox')));
        $sent = $this->mailbox->search($user, new MailSearch(after: $since, folder: 'sent', limit: config('work_context.limits.gmail_sent')));

        return collect([...$received->messages, ...$sent->messages])
            ->map(fn (FoundMessage $found): MailMessage => $found->message)
            ->unique('ref')
            ->sortByDesc(fn (MailMessage $email): int => $email->date->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Files created, edited or shared in the window. Drive reports all of
     * them as modified.
     *
     * @return list<array{document: DocumentSummary, excerpt: ?string}>
     */
    private function documents(User $user, CarbonImmutable $since): array
    {
        $documents = $this->documents->search($user, new DocumentSearch(modifiedAfter: $since, limit: config('work_context.limits.documents')))->documents;

        return array_map(fn (DocumentSummary $document, int $position): array => [
            'document' => $document,
            'excerpt' => $position < config('work_context.limits.excerpts')
                ? $this->excerpt(fn (): ?string => $this->documents->find($user, $document->ref)?->plainText())
                : null,
        ], $documents, array_keys($documents));
    }

    /**
     * Notion from the highest pages downward, with an excerpt only of the
     * first roots. Deeper levels are titles, so the crawl stays small.
     *
     * @return list<array{page: WikiPageSummary, depth: int, excerpt: ?string}>
     */
    private function pages(User $user): array
    {
        $outline = $this->wiki->outline($user, config('work_context.limits.notion_depths'));
        $excerpts = 0;

        return array_map(function (array $item) use ($user, &$excerpts): array {
            $excerpt = null;

            if ($item['depth'] === 0 && $excerpts < config('work_context.limits.excerpts')) {
                $excerpts++;
                $excerpt = $this->excerpt(fn (): ?string => $this->wiki->page($user, $item['page']->ref)?->markdown);
            }

            return ['page' => $item['page'], 'depth' => $item['depth'], 'excerpt' => $excerpt];
        }, $outline);
    }

    /**
     * The beginning of a document, or nothing when it can not be opened.
     *
     * @param  callable(): ?string  $content
     */
    private function excerpt(callable $content): ?string
    {
        try {
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) $content()));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $text === '' ? null : Str::limit($text, config('work_context.limits.excerpt_length'));
    }
}
