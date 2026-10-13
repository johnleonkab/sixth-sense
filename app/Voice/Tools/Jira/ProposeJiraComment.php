<?php

namespace App\Voice\Tools\Jira;

use App\Connections\Capability;
use App\Connectors\Issues\IssueComment;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proposes a comment on an issue, or an answer to one of its comments,
 * written as the person. Nothing is posted until they confirm.
 */
class ProposeJiraComment implements VoiceTool
{
    use UsesIssues;

    public function name(): string
    {
        return 'propose_jira_comment';
    }

    public function description(): string
    {
        return 'Propone un comentario en un issue de Jira, o una respuesta a uno de sus comentarios (reply_to: su número o comment_id, de open_jira_issue). En Jira no hay hilos: la respuesta cita el comentario y menciona a su autor. '
            .'mentions: personas a mencionar, por nombre; escribe "@Nombre" donde vayan. Se muestra como borrador y NO se publica hasta que el usuario lo confirme. Escríbelo como él, siguiendo sus reglas de Jira.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'issue' => ['type' => 'string', 'description' => 'issue_id o clave.'],
                'comment' => ['type' => 'string', 'description' => 'Texto, en markdown.'],
                'reply_to' => ['type' => 'string', 'description' => 'Número o comment_id del comentario al que responde.'],
                'mentions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['issue', 'comment'],
        ];
    }

    public function rules(): array
    {
        return [
            'issue' => ['required', 'string', 'max:300'],
            'comment' => ['required', 'string', 'max:10000'],
            'reply_to' => ['nullable', 'string', 'max:200'],
            'mentions' => ['nullable', 'array', 'max:10'],
            'mentions.*' => ['string', 'max:120'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            $issue = $this->issueNamed($user, $arguments['issue']);
            $ref = $issue->summary->ref;

            if (! $this->issues->allows($user, $ref, Capability::JiraComment)) {
                throw new RuntimeException('Esta cuenta de Jira no tiene permiso para comentar. Puede activarlo en Configuración (Conexiones).');
            }

            $replyTo = null;

            if (filled($arguments['reply_to'] ?? null)) {
                $wanted = trim($arguments['reply_to']);
                $replyTo = ctype_digit($wanted) ? ($issue->comments[(int) $wanted - 1] ?? null) : collect($issue->comments)->first(fn (IssueComment $comment): bool => in_array($wanted, [$comment->ref, $comment->id], true));
                $replyTo ?? throw new RuntimeException('No encuentro ese comentario en el issue. Ábrelo de nuevo para ver sus números.');
            }

            $mentions = $this->peopleNamed($user, self::siteOf($ref), $arguments['mentions'] ?? []);

            // An answer goes to its author, by name: Jira has no threads.
            if ($replyTo && $replyTo->authorId && ! $replyTo->mine) {
                $mentions[$replyTo->author] = $replyTo->authorId;
            }
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        $text = trim($arguments['comment']);

        foreach (array_keys($mentions) as $name) {
            if (! str_contains($text, "@{$name}")) {
                $text = "@{$name} {$text}";
            }
        }

        if ($replyTo) {
            $text = '> '.Str::limit(Str::squish($replyTo->text), 200)."\n\n{$text}";
        }

        $action = $this->actions->propose($user, 'jira.comment', ($replyTo ? "Responder a {$replyTo->author} en " : 'Comentar en ').$issue->summary->key, [
            'ref' => $ref,
            'markdown' => $text,
            'mentions' => $mentions,
        ]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['issue' => $issue->summary->key, 'comment' => $text]),
            canvas: new Canvas('jira.draft', [
                'action' => $action->toState(),
                'kind' => 'comment',
                'target' => $issue->summary->toArray($context->timezone()),
                'comment' => $text,
                'author' => $user->name,
                'in_reply_to' => $replyTo ? ['author' => $replyTo->author, 'text' => Str::limit($replyTo->text, 400)] : null,
                'mentions' => array_keys($mentions),
            ], layout: 'wide'),
        );
    }
}
