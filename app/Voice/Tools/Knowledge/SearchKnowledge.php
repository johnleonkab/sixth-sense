<?php

namespace App\Voice\Tools\Knowledge;

use App\Knowledge\KnowledgeBase;
use App\Knowledge\KnowledgeSource;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * One search over the index of the work archive, the Jira issues the person
 * takes part in, the Notion pages they shared, their meetings and their
 * answers. It reads the database, not the services, so it answers fast.
 */
class SearchKnowledge implements VoiceTool
{
    public function __construct(private KnowledgeBase $knowledge) {}

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Busca por contenido a la vez en el archivo de trabajo, en los issues de Jira en los que participa y en las páginas de Notion compartidas. '
            .'Úsalo antes de responder sobre un proyecto, una decisión, un proceso, una persona o cualquier contexto del trabajo. '
            .'Devuelve títulos con su fuente (y un resumen en los documentos más usados); para leer uno, read_knowledge con su id, que lo lee en vivo. '
            .'Lo editado en los últimos minutos puede no estar todavía: entonces usa search_jira o search_notion.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Qué buscar, con las palabras que aparecerían en el texto.'],
                'sources' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => array_column(KnowledgeSource::cases(), 'value')],
                    'description' => 'Solo estas fuentes. Vacío, todas.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:300'],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', 'in:'.implode(',', array_column(KnowledgeSource::cases(), 'value'))],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $sources = array_map(KnowledgeSource::from(...), $arguments['sources'] ?? []);
        $results = $this->knowledge->search($user, $arguments['query'], $sources);

        if ($results === []) {
            return new ToolResult([
                'results' => [],
                'note' => 'No hay nada guardado que encaje. Prueba otras palabras, o busca en vivo con search_jira o search_notion.',
            ]);
        }

        return new ToolResult(['results' => $results]);
    }
}
