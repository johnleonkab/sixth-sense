<?php

namespace App\Voice\Tools\Knowledge;

use App\Knowledge\KnowledgeSync;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Reads one document found with search_knowledge, whole. One that was not
 * copied yet is read from its service now.
 */
class ReadKnowledge implements VoiceTool
{
    public function __construct(private KnowledgeSync $sync) {}

    public function name(): string
    {
        return 'read_knowledge';
    }

    public function description(): string
    {
        return 'Lee entero un resultado de search_knowledge (archivo, Jira o Notion) por su id. No lo muestra en pantalla ni lo modifica.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'id del resultado de search_knowledge.'],
            ],
            'required' => ['id'],
        ];
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'integer'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $document = $user->knowledgeDocuments()->find($arguments['id']);

        if ($document === null) {
            return ToolResult::error('Ese resultado ya no está. Busca otra vez.');
        }

        if ($document->indexed_at === null && ! $this->sync->refresh($document)) {
            return ToolResult::error('Ya no está en su servicio.');
        }

        return new ToolResult([
            'source' => $document->source->label(),
            'title' => $document->title,
            'url' => $document->url,
            'ref' => $document->ref,
            'content' => mb_substr((string) $document->content, 0, 12000),
        ]);
    }
}
