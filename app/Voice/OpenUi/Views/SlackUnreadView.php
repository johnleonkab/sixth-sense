<?php

namespace App\Voice\OpenUi\Views;

use App\Connectors\Chat\UnreadMessage;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;
use Illuminate\Support\Str;

/**
 * What is waiting in Slack, numbered: direct messages and mentions, why each
 * one is here, who wrote it and what. A click opens the conversation.
 */
class SlackUnreadView
{
    /**
     * @param  list<UnreadMessage>  $unread
     */
    public static function canvas(User $user, array $unread, string $timezone): Canvas
    {
        $count = count($unread);
        $view = new ViewBuilder(match ($count) {
            0 => 'Nada pendiente en Slack',
            1 => '1 pendiente en Slack',
            default => "{$count} pendientes en Slack",
        });
        $items = [];

        foreach ($unread as $index => $item) {
            $n = $index + 1;
            $message = $item->message;
            $files = count($message->files);

            $items[] = [
                'n' => $n,
                'title' => $message->authorName,
                'eyebrow' => $item->reason === 'dm'
                    ? 'Mensaje directo'.($item->count > 1 ? " · {$item->count} sin leer" : '')
                    : "Te mencionan en {$message->conversationLabel}",
                'snippet' => Str::limit(trim($message->text), 280),
                'meta' => Dates::relative($message->sentAt, $timezone).($files > 0 ? ' · '.($files === 1 ? '1 archivo' : "{$files} archivos") : ''),
                'highlight' => true,
            ];

            $view->openable($n, Str::limit(trim($message->text), 80), 'show_slack_conversation', ['message_id' => $message->ref]);
        }

        $view->add('ItemList', [$items, 'No tienes mensajes directos ni menciones sin leer.']);

        return $view->canvas($user, 'Slack', 'focus');
    }
}
