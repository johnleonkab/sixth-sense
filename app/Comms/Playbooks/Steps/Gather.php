<?php

namespace App\Comms\Playbooks\Steps;

use App\Comms\Context\ContextEngine;
use App\Comms\Context\Precedence;
use App\Models\Comms\CommsDocument;
use App\Models\Comms\CommsIntent;
use App\Models\User;

/**
 * Conversation, archive and search for one intent.
 */
class Gather
{
    public function __construct(private ContextEngine $context, private Precedence $precedence) {}

    /**
     * @return array{conversation: list<array<string, mixed>>, hits: list<array{document: CommsDocument, similarity: float}>, findings: list<array{refs: list<string>, summary: string}>, archive: array<string, mixed>}
     */
    public function forIntent(User $user, CommsIntent $intent): array
    {
        $hits = $this->context->search($user, $intent->what, [], 5);

        return [
            'conversation' => $this->context->conversation($intent),
            'hits' => $hits,
            'findings' => $this->precedence->findings(array_map(
                fn (array $hit): CommsDocument => $hit['document'],
                $hits,
            )),
            'archive' => $this->context->archive($user),
        ];
    }
}
