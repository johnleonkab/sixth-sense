<?php

namespace App\Voice\OpenUi\Data;

use App\Knowledge\KnowledgeSource;
use App\Models\KnowledgeDocument;
use App\Models\User;

/**
 * The knowledge index as rows, one per document: what each source has
 * indexed, what is read most, and what was summarized.
 */
class KnowledgeRows implements Dataset
{
    public function name(): string
    {
        return 'knowledge';
    }

    public function description(): string
    {
        return 'El índice de conocimiento como filas (source, title, views, summarized, last_viewed, updated, indexed), uno por documento. source: archive, jira, notion, meeting o memory.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'source' => ['type' => 'string', 'enum' => array_column(KnowledgeSource::cases(), 'value')],
                'limit' => ['type' => 'number', 'description' => 'Como mucho 500; los más vistos primero.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'source' => ['nullable', 'in:'.implode(',', array_column(KnowledgeSource::cases(), 'value'))],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.Datasets::MAX_ROWS],
        ];
    }

    public function fields(): array
    {
        return [
            'source' => 'text',
            'title' => 'text',
            'views' => 'number',
            'summarized' => 'boolean',
            'last_viewed' => 'date',
            'updated' => 'date',
            'indexed' => 'date',
        ];
    }

    public function rows(User $user, array $arguments, string $timezone): array
    {
        return $user->knowledgeDocuments()
            ->when(isset($arguments['source']), fn ($documents) => $documents->where('source', $arguments['source']))
            ->orderByDesc('views')
            ->orderByDesc('source_updated_at')
            ->limit($arguments['limit'] ?? Datasets::MAX_ROWS)
            ->get()
            ->map(fn (KnowledgeDocument $document): array => [
                'source' => $document->source->label(),
                'title' => (string) $document->title,
                'views' => $document->views,
                'summarized' => filled($document->summary),
                'last_viewed' => $document->last_viewed_at?->setTimezone($timezone)->toDateString(),
                'updated' => $document->source_updated_at?->setTimezone($timezone)->toDateString(),
                'indexed' => $document->indexed_at?->setTimezone($timezone)->toDateString(),
            ])
            ->all();
    }
}
