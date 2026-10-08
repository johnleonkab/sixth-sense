<?php

namespace App\Voice\Tools\Docs;

use App\Actions\Actions;
use App\Connections\Capability;
use App\Connectors\Docs\Documents;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Proposes a comment, written as the person. Nothing is posted until they confirm.
 */
class ProposeDocumentComment implements VoiceTool
{
    use ProposesChanges;

    public function __construct(
        private Documents $documents,
        private Actions $actions,
    ) {}

    public function name(): string
    {
        return 'propose_document_comment';
    }

    public function description(): string
    {
        return 'Propone un comentario en un documento de Google Docs, firmado por el usuario, y lo muestra como borrador. NO lo publica: el usuario tiene que confirmarlo. '
            .'Úsala cuando pida comentar, dejar una nota, un apunte o una pregunta sobre el documento o una parte: el texto del documento no cambia. '
            .'Escríbelo en primera persona, como lo escribiría el usuario. Si es sobre una parte concreta, pon en quote el fragmento exacto del texto.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => ['type' => 'string', 'description' => 'document_id del documento abierto.'],
                'comment' => ['type' => 'string', 'description' => 'Texto del comentario.'],
                'quote' => ['type' => 'string', 'description' => 'Fragmento exacto del documento al que se refiere, si lo hay.'],
            ],
            'required' => ['document', 'comment'],
        ];
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'max:500'],
            'comment' => ['required', 'string', 'max:4000'],
            'quote' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $document = $this->documents->find($user, $arguments['document']);

        if ($document === null) {
            return ToolResult::error('No puedo abrir ese documento.');
        }

        if (! $this->documents->allows($user, $document->ref, Capability::DocsComment)) {
            return ToolResult::error('Esta cuenta no tiene permiso para comentar documentos. El usuario puede activarlo en Configuración (Conexiones) y volver a conectar la cuenta.');
        }

        $quote = filled($arguments['quote'] ?? null) ? trim($arguments['quote']) : null;

        if ($quote !== null && $document->locate($quote) === []) {
            return ToolResult::error("No encuentro «{$quote}» en el documento. Cítalo exactamente como aparece.");
        }

        $action = $this->actions->propose($user, 'docs.comment', "Comentar en «{$document->title}»", [
            'ref' => $document->ref,
            'url' => $document->url,
            'content' => $arguments['comment'],
            'quote' => $quote,
        ]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['document' => $document->title, 'comment' => $arguments['comment'], 'quote' => $quote]),
            canvas: new Canvas('docs.comment', [
                'action' => $action->toState(),
                'title' => $document->title,
                'url' => $document->url,
                'quote' => $quote,
                'comment' => $arguments['comment'],
                'author' => $user->name,
            ]),
        );
    }
}
