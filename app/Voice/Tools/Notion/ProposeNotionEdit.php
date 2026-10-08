<?php

namespace App\Voice\Tools\Notion;

use App\Connections\Capability;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Wiki\WikiEdit;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use RuntimeException;

/**
 * Proposes edits to a Notion page, anchored to its exact text. Nothing
 * changes until the person confirms.
 */
class ProposeNotionEdit implements VoiceTool
{
    use UsesWiki;

    public function name(): string
    {
        return 'propose_notion_edit';
    }

    public function description(): string
    {
        return 'Propone cambios en una página de Notion abierta con open_notion_page y los muestra como borrador; NO la cambia hasta que el usuario lo confirme. '
            .'Cada cambio se ancla en un texto EXACTO de su markdown que aparezca una sola vez (find). Tipos: replace (sustituye find por text), '
            .'insert_after / insert_before (añade text en una línea nueva junto a find), delete (borra find) y append (añade text al final; sin find). '
            .'text va en markdown ("- [ ] tarea", "## Sección"). Marcar una tarea como hecha: replace "- [ ] Tarea" por "- [x] Tarea". Cambia solo lo necesario.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'string', 'description' => 'page_id de la página.'],
                'summary' => ['type' => 'string', 'description' => 'Qué cambia, en una frase para el usuario.'],
                'edits' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => WikiEdit::TYPES],
                            'find' => ['type' => 'string', 'description' => 'Texto exacto del markdown que ancla el cambio.'],
                            'text' => ['type' => 'string', 'description' => 'Texto nuevo, en markdown.'],
                        ],
                        'required' => ['type'],
                    ],
                ],
            ],
            'required' => ['page', 'summary', 'edits'],
        ];
    }

    public function rules(): array
    {
        return [
            'page' => ['required', 'string', 'max:500'],
            'summary' => ['required', 'string', 'max:300'],
            'edits' => ['required', 'array', 'min:1', 'max:20'],
            'edits.*.type' => ['required', 'in:'.implode(',', WikiEdit::TYPES)],
            'edits.*.find' => ['nullable', 'string', 'max:4000'],
            'edits.*.text' => ['nullable', 'string', 'max:8000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $page = $this->pageNamed($user, $arguments['page']);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        if (! $this->wiki->allows($user, $page->ref, Capability::NotionEdit)) {
            return ToolResult::error('Esta cuenta de Notion no tiene permiso para editar páginas. El usuario puede activarlo en Configuración (Conexiones).');
        }

        try {
            $edits = WikiEdit::planFor($page, $arguments['edits']);
        } catch (EditNotPossible $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $action = $this->actions->propose($user, 'notion.edit', "Editar «{$page->title}»: {$arguments['summary']}", [
            'ref' => $page->ref,
            'url' => $page->url,
            'edits' => array_map(fn (WikiEdit $edit): array => $edit->toArray(), $edits),
        ]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['page' => $page->title, 'summary' => $arguments['summary'], 'changes' => count($edits)]),
            canvas: new Canvas('docs.edit', [
                'action' => $action->toState(),
                'title' => $page->title,
                'url' => $page->url,
                'source' => 'Notion',
                'changes' => array_map(fn (WikiEdit $edit): array => $edit->preview($page), $edits),
            ]),
        );
    }
}
