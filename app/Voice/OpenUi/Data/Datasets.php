<?php

namespace App\Voice\OpenUi\Data;

use Illuminate\Contracts\Container\Container;

/**
 * Every dataset a composed view may query. Only these, all read only.
 */
class Datasets
{
    /** @var list<class-string<Dataset>> */
    private const DATASETS = [
        JiraIssues::class,
        AnalyticsRows::class,
        AlertRows::class,
        CalendarEvents::class,
        KnowledgeRows::class,
        McpRows::class,
    ];

    /** Rows a query returns, at most. */
    public const MAX_ROWS = 500;

    public function __construct(private Container $container) {}

    /**
     * @return list<Dataset>
     */
    public function all(): array
    {
        return array_map(fn (string $dataset): Dataset => $this->container->make($dataset), self::DATASETS);
    }

    public function find(string $name): ?Dataset
    {
        foreach ($this->all() as $dataset) {
            if ($dataset->name() === $name) {
                return $dataset;
            }
        }

        return null;
    }

    /**
     * The datasets as OpenUI describes tools to the model, for the prompt.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>, outputSchema: array<string, mixed>, annotations: array{readOnlyHint: bool}}>
     */
    public function specs(): array
    {
        return array_map(fn (Dataset $dataset): array => [
            'name' => $dataset->name(),
            'description' => $dataset->description(),
            'inputSchema' => $dataset->parameters(),
            'outputSchema' => [
                'type' => 'object',
                'properties' => [
                    'rows' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => array_map(fn (string $type): array => ['type' => in_array($type, ['number', 'boolean'], true) ? $type : 'string'], $dataset->fields()),
                        ],
                    ],
                ],
            ],
            'annotations' => ['readOnlyHint' => true],
        ], $this->all());
    }
}
