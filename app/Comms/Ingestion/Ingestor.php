<?php

namespace App\Comms\Ingestion;

use App\Models\Comms\CommsEvent;
use App\Models\User;

/**
 * Creates the user's own person, then appends whatever is in scope.
 * Callers pass messages sorted by the time they happened.
 */
class Ingestor
{
    public function __construct(
        private IdentityResolver $people,
        private SlackNormalizer $slack,
        private GmailNormalizer $gmail,
    ) {}

    public function ingest(User $user, SlackInbound|GmailInbound $item, bool $backfill = false): ?CommsEvent
    {
        $this->people->ensureSelf($user);

        return $item instanceof SlackInbound
            ? $this->slack->ingest($user, $item, $backfill)
            : $this->gmail->ingest($user, $item, $backfill);
    }

    /**
     * @param  list<SlackInbound|GmailInbound>  $items
     */
    public function ingestAll(User $user, array $items, bool $backfill = false): void
    {
        usort($items, fn (SlackInbound|GmailInbound $left, SlackInbound|GmailInbound $right): int => $left->at <=> $right->at);

        $this->people->ensureSelf($user);

        foreach ($items as $item) {
            if ($item instanceof SlackInbound) {
                $this->slack->ingest($user, $item, $backfill);
            } else {
                $this->gmail->ingest($user, $item, $backfill);
            }
        }
    }
}
