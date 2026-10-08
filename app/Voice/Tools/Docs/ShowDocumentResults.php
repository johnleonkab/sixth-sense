<?php

namespace App\Voice\Tools\Docs;

use App\Connectors\Docs\DocumentSummary;
use App\Models\User;
use App\Voice\Tools\Canvas;
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
            .'Úsala para "búscame los documentos de prepago", "enséñame lo último que ha escrito Marta", "¿qué documentos se editaron esta semana?". '
            .'Sin filtros muestra los editados más recientemente. No leas la lista: di cuántos hay y el más relevante. Para abrir uno ("abre el 2") usa open_document con su document_id.';
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $search = $this->documentSearch($arguments, $context);
        $result = $this->documents->search($user, $search);
        $count = count($result->documents);

        return new ToolResult(
            output: [...$this->summary($result, $search, $context), 'shown_on_screen' => true],
            canvas: new Canvas('docs.results', [
                'title' => match (true) {
                    $count === 0 => 'Ningún documento',
                    $count === 1 => '1 documento',
                    default => "{$count} documentos",
                },
                'search' => $search->describe(),
                'accounts' => $result->accounts,
                'failed_accounts' => $result->failedAccounts,
                'may_have_more' => $result->mayHaveMore,
                'results' => array_map(fn (DocumentSummary $document, int $index): array => [
                    'number' => $index + 1,
                    'ref' => $document->ref,
                    'title' => $document->title,
                    'owner' => $document->owner,
                    'created_at' => $document->createdAt->setTimezone($context->timezone())->toIso8601String(),
                    'modified_at' => $document->modifiedAt->setTimezone($context->timezone())->toIso8601String(),
                    'modified_by' => $document->lastModifiedBy,
                    'account' => $document->account,
                    'url' => $document->url,
                ], $result->documents, array_keys($result->documents)),
            ], layout: 'wide'),
        );
    }
}
