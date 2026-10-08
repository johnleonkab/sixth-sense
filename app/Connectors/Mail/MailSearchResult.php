<?php

namespace App\Connectors\Mail;

final readonly class MailSearchResult
{
    /**
     * @param  list<FoundMessage>  $messages  Newest first.
     * @param  list<string>  $accounts  Accounts that were searched.
     * @param  list<string>  $failedAccounts  Accounts that could not be searched.
     * @param  int  $scanned  Candidates whose content was checked against the pattern.
     * @param  bool  $mayHaveMore  More emails may match beyond what was checked.
     */
    public function __construct(
        public array $messages,
        public array $accounts,
        public array $failedAccounts = [],
        public int $scanned = 0,
        public bool $mayHaveMore = false,
    ) {}
}
