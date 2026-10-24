<?php

namespace App\Scenes;

use App\Events\SceneUpdated;
use App\Models\PendingAction;
use App\Models\Scene;

/**
 * The cards on screen for one scene, and the broadcast that patches them.
 */
class SceneBoard
{
    /**
     * @param  list<array{key: string, title: string, state: string, text: string, error: ?string, action: ?array<string, mixed>}>  $cards
     */
    public function store(Scene $scene, string $title, array $cards): void
    {
        $scene->update([
            'canvas' => ['title' => $title, 'cards' => $cards],
        ]);

        SceneUpdated::forScene($scene->fresh() ?? $scene, null);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function fill(Scene $scene, string $key, array $patch, ?int $pendingActionId = null, ?string $error = null): void
    {
        $canvas = $scene->canvas;
        $cards = is_array($canvas['cards'] ?? null) ? $canvas['cards'] : [];

        foreach ($cards as $index => $card) {
            if (($card['key'] ?? null) === $key) {
                $cards[$index] = array_merge($card, $patch);
            }
        }

        $status = $this->status($cards);

        $scene->update([
            'status' => $status,
            'canvas' => ['title' => $canvas['title'] ?? 'Escena', 'cards' => $cards],
        ]);

        $scene->steps()->where('key', $key)->update([
            'status' => ($patch['state'] ?? null) === 'failed' ? 'failed' : 'done',
            'error' => $error,
            'pending_action_id' => $pendingActionId,
        ]);

        $offer = $status === 'ready' && $scene->origin === 'alert'
            ? '[Nota de sixth-sense, no la ha dicho el usuario] La escena del aviso ya está en pantalla, con contenido. Ofrécela en una frase. No la leas entera y no llames a más herramientas.'
            : null;

        SceneUpdated::forScene($scene->fresh() ?? $scene, $offer);
    }

    public function fail(Scene $scene, string $message): void
    {
        $canvas = $scene->canvas;
        $cards = is_array($canvas['cards'] ?? null) ? $canvas['cards'] : [];

        if ($cards === []) {
            $cards = [[
                'key' => 'scene',
                'title' => 'Escena',
                'state' => 'failed',
                'text' => '',
                'error' => $message,
                'action' => null,
            ]];
        } else {
            foreach ($cards as $index => $card) {
                if (($card['state'] ?? null) === 'empty') {
                    $cards[$index]['state'] = 'failed';
                    $cards[$index]['error'] = $message;
                }
            }
        }

        $scene->update([
            'status' => 'failed',
            'canvas' => ['title' => $canvas['title'] ?? 'Escena', 'cards' => $cards],
        ]);

        SceneUpdated::forScene($scene->fresh() ?? $scene, null);
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{key: string, title: string, state: string, text: string, error: ?string, action: ?array<string, mixed>}
     */
    public static function card(string $key, string $title, ?PendingAction $action = null): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'state' => 'empty',
            'text' => '',
            'error' => null,
            'action' => $action?->toState(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    private function status(array $cards): string
    {
        foreach ($cards as $card) {
            if (($card['state'] ?? 'empty') === 'empty') {
                return 'open';
            }
        }

        return 'ready';
    }
}
