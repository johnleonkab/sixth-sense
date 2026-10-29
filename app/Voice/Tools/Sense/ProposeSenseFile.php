<?php

namespace App\Voice\Tools\Sense;

use App\Actions\Actions;
use App\Models\User;
use App\Sense\DocumentRules;
use App\Sense\FileRules;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Proposes creating or replacing a file in the work repository. Nothing is
 * committed until the person confirms.
 */
class ProposeSenseFile implements VoiceTool
{
    public function __construct(
        private Actions $actions,
        private FileRules $rules,
    ) {}

    public function name(): string
    {
        return 'propose_sense_file';
    }

    public function description(): string
    {
        return 'Propone crear o actualizar un archivo del archivo de trabajo en GitHub y lo muestra para que la persona confirme. NO lo escribe hasta el sí. '
            .'La ruta es relativa (projects/prepago/docs/2026-10-23-cobro.md). El contenido completo del archivo va en contents, cabecera YAML incluida cuando el tipo la exige. '
            .'Si el archivo ya existe, se sustituye entero.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Ruta dentro del repositorio.'],
                'contents' => ['type' => 'string', 'description' => 'Contenido completo del archivo.'],
                'message' => ['type' => 'string', 'description' => 'Mensaje del commit, una frase.'],
            ],
            'required' => ['path', 'contents', 'message'],
        ];
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:300'],
            'contents' => ['required', 'string', 'max:100000'],
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

        $contents = $arguments['contents'];
        $problem = $this->rules->violation($repository, $path, $contents);

        if ($problem !== null) {
            return ToolResult::error($problem);
        }

        $action = $this->actions->propose($user, 'sense.write', "Guardar {$path}", [
            'path' => $path,
            'contents' => $contents,
            'message' => $arguments['message'],
        ]);

        return new ToolResult(
            output: $this->waiting($action->id, ['path' => $path]),
            canvas: new Canvas('sense.file', [
                'action' => $action->toState(),
                'path' => $path,
                'content' => $contents,
                'removing' => false,
            ], 'wide'),
        );
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function waiting(int $id, array $details): array
    {
        return [
            'pending_action_id' => $id,
            'status' => 'esperando confirmación',
            ...$details,
            'shown_on_screen' => true,
            'next_step' => 'Es solo un borrador: descríbelo en condicional ("propongo…", "¿lo guardo?"), nunca como hecho. '
                .'La app recoge su "sí", "no" o "deshaz" directamente: tú no puedes confirmarlo. '
                .'Cuando responda, consulta get_action_status y cuenta exactamente lo que diga.',
        ];
    }
}
