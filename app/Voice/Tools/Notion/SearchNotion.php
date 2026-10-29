<?php

namespace App\Voice\Tools\Notion;

use App\Connectors\Wiki\Wiki;
use App\Connectors\Wiki\WikiPageSummary;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class SearchNotion implements VoiceTool
{
    public function __construct(private Wiki $wiki) {}

    public function name(): string
    {
        return 'search_notion';
    }

    public function description(): string
    {
        return 'Busca páginas de Notion por su título y las muestra numeradas, la editada más recientemente primero. Sin query, las últimas editadas. '
            .'Para buscar a la vez en Notion y en Google Drive, usa find_files. '
            .'Para abrir una, usa open_notion_page con su page_id ("abre la 2").';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Palabras del título.'],
                'show_on_screen' => ['type' => 'boolean', 'description' => 'Mostrarlas en pantalla (por defecto sí).'],
            ],
        ];
    }

    public function rules(): array
    {
        return ['query' => ['nullable', 'string', 'max:200'], 'show_on_screen' => ['nullable', 'boolean']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $pages = $this->wiki->search($user, $arguments['query'] ?? null, 10);

        return new ToolResult(
            output: [
                'pages' => array_map(fn (WikiPageSummary $page, int $index): array => array_filter([
                    'number' => $index + 1,
                    'page_id' => $page->ref,
                    'title' => $page->title,
                    'in' => $page->parentTitle,
                    'edited' => $page->editedAt?->setTimezone($context->timezone())->locale('es')->diffForHumans(),
                    'workspace' => $page->workspace,
                ]), $pages, array_keys($pages)),
                'note' => $pages === [] ? 'No hay páginas con ese título, o no se compartieron con sixth-sense al conectar Notion.' : null,
            ],
            canvas: ($arguments['show_on_screen'] ?? true) && $pages !== []
                ? new Canvas('notion.results', [
                    'title' => filled($arguments['query'] ?? null) ? "Notion: «{$arguments['query']}»" : 'Notion: últimas editadas',
                    'pages' => array_map(fn (WikiPageSummary $page, int $index): array => [...$page->toArray($context->timezone()), 'number' => $index + 1], $pages, array_keys($pages)),
                ])
                : null,
        );
    }
}
