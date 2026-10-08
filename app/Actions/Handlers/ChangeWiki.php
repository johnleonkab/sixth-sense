<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiEdit;
use App\Models\PendingAction;
use InvalidArgumentException;

/**
 * Confirmed changes to Notion: a new page, edits, or a comment.
 */
class ChangeWiki implements ActionHandler
{
    public function __construct(private Wiki $wiki) {}

    public function execute(PendingAction $action): array
    {
        $payload = $action->payload;
        $user = $action->user;

        return match ($action->type) {
            'notion.create' => ['url' => $this->wiki->create($user, $payload['parent'], $payload['title'], $payload['markdown'])->url],
            'notion.edit' => $this->edit($action),
            'notion.comment' => ['comment_id' => $this->wiki->comment($user, $payload['ref'], $payload['content'], $payload['thread'] ?? null), 'url' => $payload['url'] ?? null],
            default => throw new InvalidArgumentException("Unknown Notion change [{$action->type}]."),
        };
    }

    /**
     * @return array{url: ?string}
     */
    private function edit(PendingAction $action): array
    {
        $this->wiki->edit($action->user, $action->payload['ref'], array_map(
            fn (array $edit): WikiEdit => new WikiEdit($edit['type'], $edit['find'], $edit['text']),
            $action->payload['edits'],
        ));

        return ['url' => $action->payload['url'] ?? null];
    }
}
