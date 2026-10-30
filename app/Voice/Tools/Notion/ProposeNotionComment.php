<?php

namespace App\Voice\Tools\Notion;

use App\Connections\Capability;
use App\Connectors\Docs\DocumentComment;
use App\Models\User;
use App\Voice\OpenUi\Views\ProposalViews;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use RuntimeException;

/**
 * Proposes a comment on a Notion page, or a reply in one of its threads,
 * written as the person. Nothing is posted until they confirm.
 */
class ProposeNotionComment implements VoiceTool
{
    use UsesWiki;

    public function name(): string
    {
        return 'propose_notion_comment';
    }

    public function description(): string
    {
        return 'Propone un comentario en una página de Notion, o una respuesta a uno de sus comentarios (reply_to: su número en pantalla o su thread_id, de open_notion_page), firmado por el usuario. '
            .'Se muestra como borrador y NO se publica hasta que el usuario lo confirme. Escríbelo en primera persona. '
            .'Notion no permite empezar un comentario sobre un fragmento concreto: los nuevos van en la página.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'string', 'description' => 'page_id de la página.'],
                'comment' => ['type' => 'string', 'description' => 'Texto del comentario.'],
                'reply_to' => ['type' => 'string', 'description' => 'Número del comentario en pantalla, o su thread_id, para responder en ese hilo.'],
            ],
            'required' => ['page', 'comment'],
        ];
    }

    public function rules(): array
    {
        return [
            'page' => ['required', 'string', 'max:500'],
            'comment' => ['required', 'string', 'max:4000'],
            'reply_to' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $page = $this->pageNamed($user, $arguments['page']);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        if (! $this->wiki->allows($user, $page->ref, Capability::NotionComment)) {
            return ToolResult::error('Esta cuenta de Notion no tiene permiso para comentar. El usuario puede activarlo en Configuración (Conexiones).');
        }

        $thread = null;

        if (filled($arguments['reply_to'] ?? null)) {
            $comments = $this->wiki->comments($user, $page->ref);
            $wanted = trim($arguments['reply_to']);
            $thread = ctype_digit($wanted) ? ($comments[(int) $wanted - 1] ?? null) : collect($comments)->first(fn (DocumentComment $comment): bool => $comment->id === $wanted);

            if ($thread === null) {
                return ToolResult::error('No encuentro ese comentario abierto en la página. Vuelve a abrirla con open_notion_page para ver sus comentarios.');
            }
        }

        $last = $thread ? ($thread->replies === [] ? ['author' => $thread->author, 'content' => $thread->content] : $thread->replies[array_key_last($thread->replies)]) : null;

        $action = $this->actions->propose($user, 'notion.comment', $thread ? "Responder a {$thread->author} en «{$page->title}»" : "Comentar en «{$page->title}»", [
            'ref' => $page->ref,
            'url' => $page->url,
            'content' => $arguments['comment'],
            'thread' => $thread?->id,
        ]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['page' => $page->title, 'comment' => $arguments['comment'], 'in_reply_to' => $thread?->author]),
            canvas: ProposalViews::comment($user, $action, 'Notion', $arguments['comment'], $thread?->quote, $last ? ['author' => $last['author'], 'content' => $last['content']] : null),
        );
    }
}
