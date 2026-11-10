<?php

namespace App\Sense;

use App\Comms\Context\Audience;
use App\Comms\Context\ContextEngine;
use App\Comms\Context\ContextRenderer;
use App\Comms\Context\ContextRequest;
use App\Connectors\GitHub\GithubContents;
use App\Models\User;
use Throwable;

/**
 * Writes what sixth-sense knows into the work archive, from the export pack:
 * how the person writes, who they talk to, what is hot, a review of the work,
 * and what they answered when asked (memory/). The services themselves stay
 * read-only; only this repository is updated, and only when they allowed
 * code updates on it.
 */
class PublishContext
{
    public function __construct(
        private GithubContents $contents,
        private SyncAgents $agents,
        private ContextEngine $context,
        private ContextRenderer $renderer,
    ) {}

    public function publish(User $user): void
    {
        $repository = $user->workRepository();

        if ($repository === null || ! $repository->mayUpdateCode()) {
            return;
        }

        $export = $this->renderer->forExport($this->context->pack($user, new ContextRequest(Audience::Export)));
        $paths = [
            'gmail' => ClaudeLayout::agentPath('gmail-writer'),
            'slack' => ClaudeLayout::agentPath('slack-writer'),
            'review' => ClaudeLayout::agentPath('review'),
        ];

        foreach ($export as $path => $contents) {
            $this->contents->update($repository, $paths[$path] ?? $path, $contents, 'Actualiza el contexto de trabajo');
        }

        try {
            $this->agents->sync($user);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
