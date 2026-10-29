<?php

namespace App\Jobs;

use App\Models\Scene;
use App\Scenes\SceneBoard;
use App\Scenes\SceneKinds;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a scene off the request, on its own queue, so the voice can listen again.
 */
class RunScene implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $sceneId)
    {
        $this->onQueue('scenes');
    }

    public function handle(SceneKinds $kinds, SceneBoard $board): void
    {
        $scene = Scene::query()->find($this->sceneId);

        if ($scene === null || $scene->status !== 'open') {
            return;
        }

        $kind = $kinds->find($scene->type);

        if ($kind === null) {
            $board->fail($scene, 'No conozco ese tipo de escena.');

            return;
        }

        try {
            $kind->run($scene);
        } catch (Throwable $exception) {
            report($exception);
            $board->fail($scene->fresh() ?? $scene, 'No he podido preparar la escena.');
        }
    }
}
