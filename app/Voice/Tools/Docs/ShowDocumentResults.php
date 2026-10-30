<?php

namespace App\Voice\Tools\Docs;

use App\Models\User;
use App\Voice\OpenUi\Views\DocumentResultsView;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ShowDocumentResults implements VoiceTool
{
    use SearchesDocuments;

    public function name(): string
    {
        return 'show_document_results';
    }

    public function description(): string
    {
        return 'Busca documentos de Google Docs y los muestra en pantalla en una lista numerada con nombre, creador, fecha de creación y última edición. '
            .'Para encontrar algo de lo que se está hablando, en Drive (Docs, Sheets, Slides) y en Notion a la vez, usa find_files. '
            .'Úsala para filtros: "enséñame lo último que ha escrito Marta", "¿qué documentos se editaron esta semana?". '
            .'Sin filtros muestra los editados más recientemente. No leas la lista: di cuántos hay y el más relevante. Para abrir uno ("abre el 2") usa open_document con su document_id.';
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $search = $this->documentSearch($arguments, $context);
        $result = $this->documents->search($user, $search);
        $count = count($result->documents);

        return new ToolResult(
            output: [...$this->summary($result, $search, $context), 'shown_on_screen' => true],
            canvas: DocumentResultsView::canvas($user, $search, $result, $context->timezone()),
        );
    }
}
