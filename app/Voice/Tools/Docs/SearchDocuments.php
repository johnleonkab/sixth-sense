<?php

namespace App\Voice\Tools\Docs;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class SearchDocuments implements VoiceTool
{
    use SearchesDocuments;

    public function name(): string
    {
        return 'search_documents';
    }

    public function description(): string
    {
        return 'Busca documentos de Google Docs para responder SOLO por voz ("¿quién editó el PRD de prepago?", "¿tengo algún documento de Grupo Vela?"). '
            .'Si pide ver, listar o buscar documentos en pantalla, usa show_document_results; para verlo entero, open_document.';
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $search = $this->documentSearch($arguments, $context);

        return new ToolResult($this->summary($this->documents->search($user, $search), $search, $context));
    }
}
