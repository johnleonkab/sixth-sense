<?php

namespace App\WorkContext;

use App\Connectors\Chat\Chats;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearch;
use App\Connectors\Docs\DocumentSummary;
use App\Connectors\Mail\FoundMessage;
use App\Connectors\Mail\Mailbox;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Mail\MailSearch;
use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiPageSummary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads the person's recent activity from Slack, Gmail, Google Docs and
 * Notion. Only read methods: it never changes anything in the services.
 * A source that fails is left out; the others are still read.
 */
class WorkActivityCollector
{
    public function __construct(
        private Chats $chats,
        private Mailbox $mailbox,
        private Documents $documents,
        private Wiki $wiki,
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
            pages: $read('notion', fn (): array => $this->pages($user, $now->subDays(config('work_context.days.documents')))),
            failedSources: $failed,
        );
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
     * Documents created, edited or shared lately (all of them show as modified).
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
     * Notion pages edited lately.
     *
     * @return list<array{page: WikiPageSummary, excerpt: ?string}>
     */
    private function pages(User $user, CarbonImmutable $since): array
    {
        $pages = array_values(array_filter(
            $this->wiki->search($user, null, config('work_context.limits.pages')),
            fn (WikiPageSummary $page): bool => $page->editedAt === null || $page->editedAt->greaterThanOrEqualTo($since),
        ));

        return array_map(fn (WikiPageSummary $page, int $position): array => [
            'page' => $page,
            'excerpt' => $position < config('work_context.limits.excerpts')
                ? $this->excerpt(fn (): ?string => $this->wiki->page($user, $page->ref)?->markdown)
                : null,
        ], $pages, array_keys($pages));
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
