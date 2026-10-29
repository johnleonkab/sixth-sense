<?php

namespace App\Scenes;

use App\Alerts\AlertSource;
use App\Models\InboxItem;
use App\Models\Scene;

/**
 * An alert opens a scene only when it already matches a kind.
 * Anything else keeps today's text proposal.
 */
class SceneMatch
{
    public function __construct(private OpenScene $scenes) {}

    public function openFor(InboxItem $item): ?Scene
    {
        $existing = $item->scene;

        if ($existing !== null) {
            return $existing;
        }

        $matched = $this->match($item);

        if ($matched === null) {
            return null;
        }

        return $this->scenes->open($item->user, $matched['type'], $matched['input'], 'alert', $item->id);
    }

    /**
     * @return array{type: string, input: array<string, mixed>}|null
     */
    private function match(InboxItem $item): ?array
    {
        $project = $item->payload['project'] ?? null;

        if ($item->source === AlertSource::Jira && is_string($project) && $project !== '' && trim((string) $item->body) !== '') {
            return [
                'type' => 'stories_from_notes',
                'input' => ['notes' => (string) $item->body, 'project' => $project],
            ];
        }

        if ($item->source === AlertSource::Gmail && preg_match('/hueco|quedar|reuni[oó]n/iu', $item->title.' '.(string) $item->body) === 1 && $item->external_id !== '') {
            return [
                'type' => 'slot_then_reply',
                'input' => ['email_id' => $item->external_id, 'timezone' => 'Europe/Madrid'],
            ];
        }

        return null;
    }
}
