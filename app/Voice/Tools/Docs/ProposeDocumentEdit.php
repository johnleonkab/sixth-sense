<?php

namespace App\Voice\Tools\Docs;

use App\Actions\Actions;
use App\Connections\Capability;
use App\Connectors\Docs\DocumentEdit;
use App\Connectors\Docs\Documents;
use App\Connectors\Docs\EditNotPossible;
use App\Connectors\Docs\EditPlan;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Proposes changes to a document. Nothing is written until the person confirms.
 */
class ProposeDocumentEdit implements VoiceTool
{
    use ProposesChanges;

    private const MAX_EDITS = 10;

    public function __construct(
        private Documents $documents,
        private Actions $actions,
    ) {}

    public function name(): string
    {
        return 'propose_document_edit';
    }

    public function description(): string
    {
        return 'Propone cambios EN EL TEXTO de un documento de Google Docs y los muestra como borrador. NO los aplica: el usuario tiene que confirmarlo. '
            .'Si pide comentar, dejar una nota u opinar sobre una parte, NO uses esta herramienta: usa propose_document_comment. '
            .'Abre antes el documento con open_document y ancla cada cambio a un texto que aparezca UNA sola vez, copiado literalmente de "text". '
            .'Cambia solo lo necesario: para "24 por 48", find = "24 horas" y text = "48 horas". '
            .'Tipos: replace (sustituye find por text), insert_after / insert_before (añade text junto a find), delete (borra find). '
            .'Para añadir un párrafo nuevo tras otro, usa insert_after con el final de ese párrafo y un text que empiece por un salto de línea.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => ['type' => 'string', 'description' => 'document_id del documento abierto.'],
                'summary' => ['type' => 'string', 'description' => 'Qué cambia, en pocas palabras: "Recordatorio a 48 horas".'],
                'edits' => [
                    'type' => 'array',
                    'description' => 'Cambios a aplicar, como mucho '.self::MAX_EDITS.'.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => DocumentEdit::TYPES],
                            'find' => ['type' => 'string', 'description' => 'Texto exacto del documento que ancla el cambio.'],
                            'text' => ['type' => 'string', 'description' => 'Texto nuevo (vacío para delete).'],
                        ],
                        'required' => ['type', 'find'],
                    ],
                ],
            ],
            'required' => ['document', 'summary', 'edits'],
        ];
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'max:500'],
            'summary' => ['required', 'string', 'max:160'],
            'edits' => ['required', 'array', 'min:1', 'max:'.self::MAX_EDITS],
            'edits.*.type' => ['required', 'in:'.implode(',', DocumentEdit::TYPES)],
            'edits.*.find' => ['required', 'string', 'max:2000'],
            'edits.*.text' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $document = $this->documents->find($user, $arguments['document']);

        if ($document === null) {
            return ToolResult::error('No puedo abrir ese documento.');
        }

        if (! $document->editable) {
            return ToolResult::error('Es un archivo de Word guardado en Drive: no se puede editar desde aquí. Puedes proponer un comentario o que lo abra en Drive.');
        }

        if (! $this->documents->allows($user, $document->ref, Capability::DocsEdit)) {
            return ToolResult::error('Esta cuenta no tiene permiso para editar documentos. El usuario puede activarlo en Configuración (Conexiones) y volver a conectar la cuenta.');
        }

        try {
            $plan = EditPlan::for($document, array_map(
                fn (array $edit): DocumentEdit => new DocumentEdit($edit['type'], $edit['find'], $edit['text'] ?? ''),
                $arguments['edits'],
            ));
        } catch (EditNotPossible $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $action = $this->actions->propose($user, 'docs.edit', $arguments['summary'], [
            'plan' => [
                'ref' => $plan->ref,
                'title' => $plan->title,
                'revision_id' => $plan->revisionId,
                'changes' => $plan->changes,
                'operations' => $plan->operations,
                'url' => $plan->url,
            ],
        ]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['document' => $plan->title, 'summary' => $arguments['summary'], 'changes' => count($plan->changes)]),
            canvas: new Canvas('docs.edit', [
                'action' => $action->toState(),
                'title' => $plan->title,
                'url' => $plan->url,
                'changes' => $plan->changes,
            ]),
        );
    }
}
