<?php

namespace App\Voice\Tools\Docs;

use App\Connectors\Docs\Documents;
use App\Connectors\Docs\DocumentSearch;
use App\Connectors\Docs\DocumentSearchResult;
use App\Connectors\Docs\DocumentSummary;
use App\Voice\Tools\ToolContext;
use Carbon\CarbonImmutable;

/**
 * Shared arguments and output for the document search tools.
 */
trait SearchesDocuments
{
    public function __construct(private Documents $documents) {}

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Palabras del nombre del documento, por ejemplo "prepago".'],
                'text' => ['type' => 'string', 'description' => 'Palabras que aparecen dentro del documento.'],
                'owner' => ['type' => 'string', 'description' => 'Creador o propietario: nombre o email, por ejemplo "Marta".'],
                'modified_after' => ['type' => 'string', 'description' => 'Editado desde este día incluido, YYYY-MM-DD.'],
                'modified_before' => ['type' => 'string', 'description' => 'Editado hasta este día incluido, YYYY-MM-DD.'],
                'created_after' => ['type' => 'string', 'description' => 'Creado desde este día incluido, YYYY-MM-DD.'],
                'created_before' => ['type' => 'string', 'description' => 'Creado hasta este día incluido, YYYY-MM-DD.'],
                'limit' => ['type' => 'integer', 'description' => 'Máximo de resultados, de 1 a 25. Por defecto 15.'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'text' => ['nullable', 'string', 'max:200'],
            'owner' => ['nullable', 'string', 'max:120'],
            'modified_after' => ['nullable', 'date_format:Y-m-d'],
            'modified_before' => ['nullable', 'date_format:Y-m-d'],
            'created_after' => ['nullable', 'date_format:Y-m-d'],
            'created_before' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function documentSearch(array $arguments, ToolContext $context): DocumentSearch
    {
        $date = fn (?string $value): ?CarbonImmutable => $value ? CarbonImmutable::parse($value, $context->timezone())->startOfDay() : null;

        return new DocumentSearch(
            name: $arguments['name'] ?? null,
            text: $arguments['text'] ?? null,
            owner: $arguments['owner'] ?? null,
            modifiedAfter: $date($arguments['modified_after'] ?? null),
            modifiedBefore: $date($arguments['modified_before'] ?? null),
            createdAfter: $date($arguments['created_after'] ?? null),
            createdBefore: $date($arguments['created_before'] ?? null),
            limit: (int) ($arguments['limit'] ?? 15),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(DocumentSearchResult $result, DocumentSearch $search, ToolContext $context): array
    {
        $when = fn (CarbonImmutable $date): string => $date->setTimezone($context->timezone())->locale('es')->isoFormat('D MMM YYYY, HH:mm');

        return array_filter([
            'search' => $search->describe() ?: null,
            'total_results' => count($result->documents),
            'results' => array_map(fn (DocumentSummary $document, int $index): array => array_filter([
                'number' => $index + 1,
                'document_id' => $document->ref,
                'title' => $document->title,
                'owner' => $document->owner['name'] ?? $document->owner['email'] ?? null,
                'created' => $when($document->createdAt),
                'modified' => $when($document->modifiedAt),
                'modified_by' => $document->lastModifiedBy['name'] ?? null,
            ]), $result->documents, array_keys($result->documents)),
            'may_have_more' => $result->mayHaveMore ?: null,
            'unreadable_accounts' => $result->failedAccounts ?: null,
            'note' => $result->accounts === [] && $result->failedAccounts === []
                ? 'El usuario no tiene ninguna cuenta con documentos conectada. Dile que puede conectarla en Configuración.'
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
