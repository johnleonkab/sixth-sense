<?php

namespace App\Scenes;

use App\Jobs\RunScene;
use App\Models\Scene;
use App\Models\User;
use App\Scenes\Kinds\SceneKind;
use InvalidArgumentException;

/**
 * Opens a scene on the empty canvas and queues the work.
 * The canvas returned is the empty one: the job has not filled it.
 */
class OpenScene
{
    public function __construct(private SceneKinds $kinds) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(User $user, string $type, array $input, string $origin = 'voice', ?int $alertId = null): Scene
    {
        $kind = $this->kinds->find($type);

        if (! $kind instanceof SceneKind) {
            throw new InvalidArgumentException("Unknown scene type [{$type}].");
        }

        $cards = $kind->blank($input);

        $scene = $user->scenes()->create([
            'type' => $type,
            'status' => 'open',
            'origin' => $origin,
            'inbox_item_id' => $alertId,
            'input' => $input,
            'canvas' => ['title' => $kind->title(), 'cards' => $cards],
        ]);

        foreach ($cards as $card) {
            $scene->steps()->create([
                'layer' => 2,
                'key' => $card['key'],
                'status' => 'pending',
                'payload' => [],
            ]);
        }

        RunScene::dispatch($scene->id);

        return $scene;
    }

    /**
     * What the screen shows at once. Not re-read after the job.
     *
     * @return array<string, mixed>
     */
    public function canvas(Scene $scene): array
    {
        return [
            'scene_id' => $scene->id,
            'type' => $scene->type,
            'title' => $scene->canvas['title'] ?? 'Escena',
            'cards' => $scene->canvas['cards'] ?? [],
        ];
    }
}
