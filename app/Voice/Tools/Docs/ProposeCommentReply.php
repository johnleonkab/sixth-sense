<?php

namespace App\Voice\Tools\Docs;

use App\Actions\Actions;
use App\Connections\Capability;
use App\Connectors\Docs\DocumentComment;
use App\Connectors\Docs\Documents;
use App\Models\User;
use App\Voice\OpenUi\Views\ProposalViews;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Proposes a reply to a comment on a document, written as the person, and
 * optionally resolving it. Nothing is posted until they confirm.
 */
class ProposeCommentReply implements VoiceTool
{
    use ProposesChanges;

    public function __construct(
        private Documents $documents,
        private Actions $actions,
    ) {}

    public function name(): string
    {
        return 'propose_comment_reply';
    }

    public function description(): string
    {
        return 'Propone una respuesta a un comentario de un documento de Google Docs, firmada por el usuario, y la muestra como borrador. NO la publica: el usuario tiene que confirmarla. '
            .'El comentario se indica con su número en pantalla o su comment_id, de open_document. Con resolve, además lo marca como resuelto ("respóndele y ciérralo"). '
            .'Escríbela en primera persona, como lo haría el usuario.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document' => ['type' => 'string', 'description' => 'document_id del documento abierto.'],
                'comment' => ['type' => 'string', 'description' => 'Número del comentario en pantalla, o su comment_id.'],
                'reply' => ['type' => 'string', 'description' => 'Texto de la respuesta.'],
                'resolve' => ['type' => 'boolean', 'description' => 'Marcar el comentario como resuelto al responder.'],
            ],
            'required' => ['document', 'comment', 'reply'],
        ];
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'max:500'],
            'comment' => ['required', 'string', 'max:200'],
            'reply' => ['required', 'string', 'max:4000'],
            'resolve' => ['nullable', 'boolean'],
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

        $comments = $this->documents->comments($user, $document->ref);
        $wanted = trim($arguments['comment']);
        $comment = ctype_digit($wanted)
            ? ($comments[(int) $wanted - 1] ?? null)
            : collect($comments)->first(fn (DocumentComment $comment): bool => $comment->id === $wanted);

        if ($comment === null) {
            return ToolResult::error('No encuentro ese comentario abierto en el documento. Vuelve a abrirlo con open_document para ver sus comentarios.');
        }

        $resolve = (bool) ($arguments['resolve'] ?? false);
        $last = $comment->replies === [] ? ['author' => $comment->author, 'content' => $comment->content] : $comment->replies[array_key_last($comment->replies)];

        $action = $this->actions->propose($user, 'docs.reply', ($resolve ? 'Responder y resolver' : 'Responder').' el comentario de '.$comment->author, [
            'ref' => $document->ref,
            'url' => $document->url,
            'comment_id' => $comment->id,
            'content' => $arguments['reply'],
            'resolve' => $resolve,
        ]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['document' => $document->title, 'comment_by' => $comment->author, 'reply' => $arguments['reply'], 'resolve' => $resolve]),
            canvas: ProposalViews::comment($user, $action, 'Docs', $arguments['reply'], $comment->quote, ['author' => $last['author'], 'content' => $last['content']], $resolve),
        );
    }
}
