<?php

namespace App\WorkContext;

use App\Connectors\Chat\ChatMessage;
use App\Connectors\Docs\DocumentSummary;
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
     * @param  list<array{page: WikiPageSummary, excerpt: ?string}>  $pages  Notion pages edited lately, newest first.
     * @param  list<string>  $failedSources  Sources that could not be read.
     */
    public function __construct(
        public array $messages = [],
        public array $emails = [],
        public array $documents = [],
        public array $pages = [],
        public array $failedSources = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->messages === [] && $this->emails === [] && $this->documents === [] && $this->pages === [];
    }

    /**
     * How much was read from each source.
     *
     * @return array{slack: int, gmail: int, docs: int, notion: int}
     */
    public function counts(): array
    {
        return [
            'slack' => count($this->messages),
            'gmail' => count($this->emails),
            'docs' => count($this->documents),
            'notion' => count($this->pages),
        ];
    }
}
