<?php

namespace App\Voice\Tools\Sense;

use App\Connectors\GitHub\GithubApi;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Lists the files in the work repository so an agent can see where things are
 * before reading or changing one.
 */
class ListSenseFiles implements VoiceTool
{
    public function __construct(private GithubApi $api) {}

    public function name(): string
    {
        return 'list_sense_files';
    }

    public function description(): string
    {
        return 'Lista los archivos del archivo de trabajo en GitHub. Úsalo para saber qué hay antes de leer o proponer un cambio. No escribe nada.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function rules(): array
    {
        return [];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $repository = $user->workRepository();

        if ($repository === null) {
            return ToolResult::error('No hay un archivo de trabajo. Se elige en Configuración, Conexiones.');
        }

        $paths = $this->api->paths($repository);

        return new ToolResult([
            'repository' => $repository->full_name,
            'files' => array_slice($paths, 0, 200),
            'truncated' => count($paths) > 200,
        ]);
    }
}
