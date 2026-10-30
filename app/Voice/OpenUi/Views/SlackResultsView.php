<?php

namespace App\Voice\OpenUi\Views;

use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\ChatSearch;
use App\Models\User;
use App\Voice\OpenUi\Dates;
use App\Voice\OpenUi\ViewBuilder;
use App\Voice\Tools\Canvas;
use Illuminate\Support\Str;

/**
 * Slack messages found: what was searched, then each message numbered with
 * where and who, the searched words marked. A click opens the conversation.
 */
class SlackResultsView
{
    /**
     * @param  list<ChatMessage>  $messages
     */
    public static function canvas(User $user, ChatSearch $search, array $messages, string $timezone): Canvas
    {
        $view = new ViewBuilder(count($messages) === 1 ? '1 mensaje' : count($messages).' mensajes');
        $described = $search->describe();

        $chips = array_values(array_map(fn (string $key, string $value): string => match ($key) {
            'text' => "«{$value}»",
            'from' => "de {$value}",
            'in' => "en {$value}",
            'after' => "desde {$value}",
            'before' => "hasta {$value}",
            default => $value,
        }, array_keys($described), $described));

        if ($chips !== []) {
            $view->add('Chips', [$chips]);
        }

        $items = [];

        foreach ($messages as $index => $message) {
            $n = $index + 1;

            $items[] = [
                'n' => $n,
                'title' => $message->authorName ?: 'Desconocido',
                'eyebrow' => $message->conversationLabel,
                'snippet' => Str::limit(trim($message->text), 280),
                'match' => $described['text'] ?? null,
                'meta' => Dates::relative($message->sentAt, $timezone),
                'status' => $message->replyCount > 0 ? "{$message->replyCount} respuestas" : null,
                'highlight' => $message->mentionsMe ?: null,
            ];

            $view->openable($n, Str::limit(trim($message->text), 80), 'show_slack_conversation', ['message_id' => $message->ref]);
        }

        $view->add('ItemList', [$items, 'No he encontrado mensajes.']);

        return $view->canvas($user, 'Slack', 'focus');
    }
}
