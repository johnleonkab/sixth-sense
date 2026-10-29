<?php

namespace App\Voice\Tools\Sense;

use App\Connectors\GitHub\GithubContents;
use App\Models\User;
use App\Sense\DocumentRules;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Reads one file from the work repository. Reading does not ask for confirmation.
 */
class ReadSenseFile implements VoiceTool
{
    public function __construct(private GithubContents $contents) {}

    public function name(): string
    {
        return 'read_sense_file';
    }

    public function description(): string
    {
        return 'Lee un archivo del archivo de trabajo en GitHub. La ruta es relativa. No lo modifica.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => ['type' => 'string', 'description' => 'Ruta dentro del repositorio.'],
            ],
            'required' => ['path'],
        ];
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:300'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $repository = $user->workRepository();

        if ($repository === null) {
            return ToolResult::error('No hay un archivo de trabajo. Se elige en Configuración, Conexiones.');
        }

        $path = DocumentRules::path($arguments['path']);

        if ($path === null) {
            return ToolResult::error('La ruta no es válida.');
        }

        $file = $this->contents->read($repository, $path);

        if ($file === null) {
            return ToolResult::error("No está el archivo {$path}.");
        }

        return new ToolResult([
            'path' => $path,
            'content' => mb_substr($file['content'], 0, 12000),
        ]);
    }
}
