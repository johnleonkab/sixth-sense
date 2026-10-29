<?php

namespace App\Actions\Handlers;

use App\Actions\ActionHandler;
use App\Connectors\GitHub\GithubContents;
use App\Models\PendingAction;
use App\Sense\SyncAgents;
use RuntimeException;
use Throwable;

/**
 * Writes or deletes one file in the work repository after the person confirms.
 * A change under agents/ is copied into sixth-sense straight away.
 */
class ChangeSense implements ActionHandler
{
    public function __construct(
        private GithubContents $contents,
        private SyncAgents $agents,
    ) {}

    public function execute(PendingAction $action): array
    {
        $repository = $action->user->workRepository();

        if ($repository === null || ! $repository->mayUpdateCode()) {
            throw new RuntimeException('No hay un archivo de trabajo con permiso para escribir.');
        }

        $path = (string) $action->payload['path'];
        $message = (string) $action->payload['message'];

        $result = match ($action->type) {
            'sense.write' => $this->contents->update($repository, $path, (string) $action->payload['contents'], $message),
            'sense.delete' => $this->contents->delete($repository, $path, $message),
            default => throw new RuntimeException("No sé aplicar {$action->type}."),
        };

        if (str_starts_with($path, 'agents/')) {
            try {
                $this->agents->sync($action->user);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return array_filter($result, fn (string $value): bool => $value !== '');
    }
}
