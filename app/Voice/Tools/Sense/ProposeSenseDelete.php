<?php

namespace App\Voice\Tools\Sense;

use App\Actions\Actions;
use App\Connectors\GitHub\GithubContents;
use App\Models\User;
use App\Sense\DocumentRules;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Proposes deleting a file from the work repository. Nothing is removed
 * until the person confirms.
 */
class ProposeSenseDelete implements VoiceTool
{
    public function __construct(
        private Actions $actions,
        private GithubContents $contents,
    ) {}

    public function name(): string
    {
        return 'propose_sense_delete';
    }

    public function description(): string
    {
        return 'Propone borrar un archivo del archivo de trabajo en GitHub y lo muestra para que la persona confirme. NO lo borra hasta el sí. '
            .'La ruta es relativa. No borres CLAUDE.md, map.md ni types/.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Ruta del archivo a borrar.'],
                'message' => ['type' => 'string', 'description' => 'Mensaje del commit, una frase.'],
            ],
            'required' => ['path', 'message'],
        ];
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:300'],
            'message' => ['required', 'string', 'max:200'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $repository = $user->workRepository();

        if ($repository === null || ! $repository->mayUpdateCode()) {
            return ToolResult::error('No hay un archivo de trabajo con permiso para escribir. Se elige en Configuración, Conexiones.');
        }

        $path = DocumentRules::path($arguments['path']);

        if ($path === null) {
            return ToolResult::error('La ruta no es válida.');
        }

        $file = $this->contents->read($repository, $path);

        if ($file === null) {
            return ToolResult::error("No está el archivo {$path}.");
        }

        $action = $this->actions->propose($user, 'sense.delete', "Borrar {$path}", [
            'path' => $path,
            'message' => $arguments['message'],
        ]);

        return new ToolResult(
            output: [
                'pending_action_id' => $action->id,
                'status' => 'esperando confirmación',
                'path' => $path,
                'shown_on_screen' => true,
                'next_step' => 'Es solo una propuesta: di que propones borrarlo y pregunta si lo hace. Tú no puedes confirmarlo. '
                    .'Cuando responda, consulta get_action_status y cuenta exactamente lo que diga.',
            ],
            canvas: new Canvas('sense.file', [
                'action' => $action->toState(),
                'path' => $path,
                'content' => $file['content'],
                'removing' => true,
            ], 'wide'),
        );
    }
}
