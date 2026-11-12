<?php

namespace App\Comms\Gate\WriteTools;

use App\Connectors\Wiki\Wiki;
use App\Models\Comms\CommsProposal;
use App\Models\User;
use RuntimeException;

/**
 * Creates one Notion page through the wiki connector. A second call returns
 * the id already recorded.
 */
class CreateNotionPage
{
    public function __construct(private Wiki $wiki) {}

    public function send(User $user, CommsProposal $proposal): string
    {
        if (filled($proposal->external_id)) {
            return (string) $proposal->external_id;
        }

        $payload = $proposal->payload;
        $parent = (string) ($payload['parent'] ?? '');

        if ($parent === '') {
            $parent = $this->wiki->search($user, null, 1)[0]->ref
                ?? throw new RuntimeException('No hay una página de Notion donde crear. Abre Notion y vuelve a intentarlo.');
        }

        $created = $this->wiki->create(
            $user,
            $parent,
            (string) ($payload['title'] ?? ''),
            (string) ($payload['markdown'] ?? $payload['text'] ?? ''),
        );

        return $created->ref;
    }
}
