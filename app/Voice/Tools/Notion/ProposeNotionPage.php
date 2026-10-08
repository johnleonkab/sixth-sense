<?php

namespace App\Voice\Tools\Notion;

use App\Connections\Capability;
use App\Connectors\Notion\NotionMarkdown;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use RuntimeException;

/**
 * Proposes a new Notion page inside another. Nothing is created until the
 * person confirms.
 */
class ProposeNotionPage implements VoiceTool
{
    use UsesWiki;

    public function name(): string
    {
        return 'propose_notion_page';
    }

    public function description(): string
    {
        return 'Propone crear una página de Notion dentro de otra (parent: su page_id, enlace o título) y la muestra como borrador. NO la crea hasta que el usuario lo confirme. '
            .'El contenido va en markdown: # títulos, - listas, - [ ] tareas, **negrita**, > citas, --- separadores. Si no dice dónde, pregúntale en qué página crearla.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parent' => ['type' => 'string', 'description' => 'Página dentro de la que crearla: page_id, enlace o título.'],
                'title' => ['type' => 'string', 'description' => 'Título de la página nueva.'],
                'content' => ['type' => 'string', 'description' => 'Contenido en markdown, sin repetir el título.'],
            ],
            'required' => ['parent', 'title'],
        ];
    }

    public function rules(): array
    {
        return [
            'parent' => ['required', 'string', 'max:500'],
            'title' => ['required', 'string', 'max:300'],
            'content' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $parent = $this->pageNamed($user, $arguments['parent']);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        if (! $this->wiki->allows($user, $parent->ref, Capability::NotionEdit)) {
            return ToolResult::error('Esta cuenta de Notion no tiene permiso para crear páginas. El usuario puede activarlo en Configuración (Conexiones).');
        }

        $content = trim((string) ($arguments['content'] ?? ''));
        $action = $this->actions->propose($user, 'notion.create', "Crear «{$arguments['title']}» en «{$parent->title}»", [
            'parent' => $parent->ref,
            'title' => $arguments['title'],
            'markdown' => $content,
        ]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['title' => $arguments['title'], 'inside' => $parent->title]),
            canvas: new Canvas('notion.new', [
                'action' => $action->toState(),
                'title' => $arguments['title'],
                'parent' => $parent->title,
                'blocks' => NotionMarkdown::blocks($content),
            ], layout: 'wide'),
        );
    }
}
