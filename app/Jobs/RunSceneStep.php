<?php

namespace App\Jobs;

use App\Models\Scene;
use App\Scenes\Kinds\StoriesFromNotes;
use App\Scenes\SceneBoard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One story of a scene. Dispatched together with the others, without waiting.
 */
class RunSceneStep implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $sceneId, public string $key)
    {
        $this->onQueue('scenes');
    }

    public function handle(StoriesFromNotes $stories, SceneBoard $board): void
    {
        $scene = Scene::query()->find($this->sceneId);

        if ($scene === null) {
            return;
        }

        try {
            $stories->write($scene, $this->key);
        } catch (Throwable $exception) {
            report($exception);
            $board->fill($scene->fresh() ?? $scene, $this->key, [
                'state' => 'failed',
                'error' => 'No he podido preparar esta historia.',
            ], error: 'No he podido preparar esta historia.');
        }
    }
}
