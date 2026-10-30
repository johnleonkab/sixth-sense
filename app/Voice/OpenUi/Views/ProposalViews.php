<?php

namespace App\Voice\OpenUi\Views;

use App\Models\PendingAction;
use App\Models\User;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;

/**
 * Changes that wait for the "sí", as views: what will change, and the bar
 * with Sí and Cancelar, then the seconds to undo.
 */
class ProposalViews
{
    /**
     * An attention rule for Slack: proposed (it waits for the "sí"), or
     * already saved when the person said it in so many words.
     */
    public static function rule(User $user, string $title, string $body, ?PendingAction $action): Canvas
    {
        $view = new ViewBuilder($title);
        $view->add('Text', [$body]);

        $action !== null
            ? $view->proposal($action, 'guardar', 'Regla guardada')
            : $view->add('Text', ['Queda guardado, por encima de lo aprendido.', 'muted']);

        return $view->canvas($user, 'Slack', 'focus');
    }

    /**
     * A comment, or a reply to one, signed by the person: what it answers
     * and the draft.
     *
     * @param  ?array{author: string, content: string}  $inReplyTo
     */
    public static function comment(User $user, PendingAction $action, string $source, string $comment, ?string $quote = null, ?array $inReplyTo = null, bool $resolve = false): Canvas
    {
        $view = new ViewBuilder($action->label);

        if (filled($quote)) {
            $view->add('Quote', ["«{$quote}»"]);
        }

        if ($inReplyTo !== null) {
            $view->add('Quote', [$inReplyTo['content'], $inReplyTo['author']]);
        }

        $view->add('Draft', [($inReplyTo ? 'Respuesta' : 'Comentario').' de '.$user->name.' · borrador'.($resolve ? ' · y lo marca como resuelto' : ''), $comment]);
        $view->proposal($action, 'publicar', $inReplyTo ? 'Respuesta publicada' : 'Comentario publicado');

        return $view->canvas($user, $source, 'focus');
    }
}
