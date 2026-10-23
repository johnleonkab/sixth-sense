<?php

namespace App\Sense;

use App\Actions\Actions;
use App\Actions\ActionStatus;
use App\Models\PendingAction;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Replaces the markdown a proposal would commit, then confirms it. The click
 * is the "sí": the person does not have to say it.
 */
class OverrideFile
{
    public function __construct(
        private Actions $actions,
        private FileRules $rules,
    ) {}

    /**
     * @throws ValidationException
     */
    public function apply(PendingAction $action, string $contents): PendingAction
    {
        if ($action->status === ActionStatus::Pending && $action->expires_at->isPast()) {
            $action->update(['status' => ActionStatus::Expired]);
        }

        if ($action->type !== 'sense.write' || $action->status !== ActionStatus::Pending) {
            throw ValidationException::withMessages([
                'contents' => 'Esta propuesta ya no se puede editar.',
            ]);
        }

        $path = (string) ($action->payload['path'] ?? '');

        if (! str_ends_with($path, '.md')) {
            throw ValidationException::withMessages([
                'contents' => 'Solo se puede corregir un archivo markdown.',
            ]);
        }

        $repository = $action->user->workRepository();

        if ($repository === null || ! $repository->mayUpdateCode()) {
            throw ValidationException::withMessages([
                'contents' => 'No hay un archivo de trabajo con permiso para escribir.',
            ]);
        }

        try {
            $problem = $this->rules->violation($repository, $path, $contents);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'contents' => 'No he podido comprobar las reglas de este archivo.',
            ]);
        }

        if ($problem !== null) {
            throw ValidationException::withMessages(['contents' => $problem]);
        }

        $action->update([
            'payload' => [...$action->payload, 'contents' => $contents],
        ]);

        return $this->actions->confirm($action);
    }
}
