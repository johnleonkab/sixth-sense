<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Issues\IssueChanges;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Issues\NewIssue;
use App\Models\PendingAction;
use InvalidArgumentException;

/**
 * Confirmed changes to Jira: a new issue, edits, dependencies, comments.
 */
class ChangeJira implements ActionHandler
{
    public function __construct(private IssueTracker $issues) {}

    public function execute(PendingAction $action): array
    {
        $payload = $action->payload;
        $user = $action->user;

        return match ($action->type) {
            'jira.create' => ['url' => ($created = $this->issues->create($user, (int) $payload['site'], NewIssue::fromArray($payload['issue'])))->url, 'key' => $created->key],
            'jira.update' => $this->done(fn () => $this->issues->update($user, $payload['ref'], IssueChanges::fromArray($payload['changes']))),
            'jira.link' => $this->done(fn () => $this->issues->link($user, $payload['blocker'], $payload['blocked'])),
            'jira.unlink' => $this->done(fn () => $this->issues->unlink($user, $payload['link'])),
            'jira.comment' => ['comment_id' => $this->issues->comment($user, $payload['ref'], $payload['markdown'], $payload['mentions'] ?? [])],
            default => throw new InvalidArgumentException("Unknown Jira change [{$action->type}]."),
        };
    }

    /**
     * @return array{}
     */
    private function done(callable $change): array
    {
        $change();

        return [];
    }
}
