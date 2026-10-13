<?php

namespace App\Connectors\Jira;

use App\Alerts\AlertSource;
use App\Alerts\InboxCandidate;
use App\Alerts\InboxWatcher;
use App\Connectors\Mail\MailAddress;
use App\Models\ConnectedAccount;
use Carbon\CarbonImmutable;

/**
 * Polls Jira for comments that mention the user, made by others since the
 * last check, in the projects their rules name.
 */
class JiraWatcher implements InboxWatcher
{
    /** @var array<int, string> Next cursor per account, applied on commit(). */
    private array $pendingCursors = [];

    public function __construct(private JiraIssueTracker $jira) {}

    public function source(): AlertSource
    {
        return AlertSource::Jira;
    }

    public function newMessages(ConnectedAccount $account): array
    {
        $now = CarbonImmutable::now();
        $this->pendingCursors[$account->id] = $now->toIso8601String();

        if ($account->watch_cursor === null) {
            return [];
        }

        $since = CarbonImmutable::parse($account->watch_cursor);

        return array_map(fn (array $found): InboxCandidate => new InboxCandidate(
            source: AlertSource::Jira,
            externalId: $found['comment']->ref,
            account: (string) $account->email,
            from: new MailAddress($found['comment']->author, null),
            to: [],
            cc: [],
            subject: "{$found['issue']->key} · {$found['issue']->summary}",
            excerpt: $found['comment']->text,
            receivedAt: $found['comment']->createdAt,
            labels: ['mention'],
            url: "{$found['issue']->url}?focusedCommentId={$found['comment']->id}",
            threadKey: $found['issue']->ref,
        ), $this->jira->mentionsSince($account, $since));
    }

    public function commit(ConnectedAccount $account): void
    {
        if (isset($this->pendingCursors[$account->id])) {
            $account->update(['watch_cursor' => $this->pendingCursors[$account->id], 'watched_at' => now()]);
            unset($this->pendingCursors[$account->id]);
        }
    }
}
