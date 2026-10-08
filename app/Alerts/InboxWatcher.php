<?php

namespace App\Alerts;

use App\Models\ConnectedAccount;

/**
 * Finds what arrived in an account since the last check. Polling today; a
 * push implementation (Gmail + Pub/Sub, Slack events) can replace it without
 * touching the rest.
 */
interface InboxWatcher
{
    /**
     * The source whose accounts it watches.
     */
    public function source(): AlertSource;

    /**
     * New messages since the account's cursor. The first time, it only sets
     * the cursor, so older messages never raise alerts.
     *
     * @return list<InboxCandidate>
     */
    public function newMessages(ConnectedAccount $account): array;

    /**
     * Move the account's cursor past what newMessages() returned. Called once
     * they have all been handled, so a failure is retried on the next check.
     */
    public function commit(ConnectedAccount $account): void;
}
