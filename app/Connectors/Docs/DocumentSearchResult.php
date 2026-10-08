<?php

namespace App\Connectors\Docs;

final readonly class DocumentSearchResult
{
    /**
     * @param  list<DocumentSummary>  $documents  Most recently modified first.
     * @param  list<string>  $accounts
     * @param  list<string>  $failedAccounts
     */
    public function __construct(
        public array $documents,
        public array $accounts,
        public array $failedAccounts = [],
        public bool $mayHaveMore = false,
    ) {}
}
