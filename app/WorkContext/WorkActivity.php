<?php

namespace App\WorkContext;

use App\Connectors\Chat\ChatMessage;
use App\Connectors\Docs\DocumentSummary;
use App\Connectors\Issues\IssueSummary;
use App\Connectors\Mail\MailMessage;
use App\Connectors\Wiki\WikiPageSummary;

/**
 * What the person did and received lately across their services, read only,
 * to be summarized into their work context.
 */
final readonly class WorkActivity
{
    /**
     * @param  list<ChatMessage>  $messages  Slack: written by, to or mentioning them, newest first.
     * @param  list<MailMessage>  $emails  Received in the primary inbox and sent, newest first.
     * @param  list<array{document: DocumentSummary, excerpt: ?string}>  $documents  Google Docs edited lately, newest first.
     * @param  list<array{page: WikiPageSummary, depth: int, excerpt: ?string}>  $pages  Notion, from the top of the workspace downward.
     * @param  list<string>  $failedSources  Sources that could not be read.
     * @param  list<IssueSummary>  $issues  Recent Jira issues, following each site's rules.
     * @param  list<array{path: string, title: string, excerpt: string}>  $archive  Work archive files changed in the last day.
     */
    public function __construct(
        public array $messages = [],
        public array $emails = [],
        public array $documents = [],
        public array $pages = [],
        public array $failedSources = [],
        public array $issues = [],
        public array $archive = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->messages === [] && $this->emails === [] && $this->documents === [] && $this->pages === [] && $this->issues === [] && $this->archive === [];
    }

    /**
     * How much was read from each source.
     *
     * @return array{slack: int, gmail: int, docs: int, notion: int, jira: int, archive: int}
     */
    public function counts(): array
    {
        return [
            'slack' => count($this->messages),
            'gmail' => count($this->emails),
            'docs' => count($this->documents),
            'notion' => count($this->pages),
            'jira' => count($this->issues),
            'archive' => count($this->archive),
        ];
    }
}
