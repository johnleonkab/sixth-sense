<?php

namespace App\Voice\Tools\Slack;

use App\Connectors\Chat\ChatConversation;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\ChatPerson;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\NameMatch;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use RuntimeException;

/**
 * Shared steps for the chat tools: turn what the person said ("#producto",
 * "Marta", an id) into a conversation or a person, asking when it is unclear.
 */
trait UsesChats
{
    public function __construct(private Chats $chats) {}

    /**
     * Ids look like "12:C0123"; anything else is a name.
     */
    private static function isRef(string $value): bool
    {
        return (bool) preg_match('/^[\w-]+:[A-Z0-9]+(:[\d.]+)?$/', $value);
    }

    /**
     * @throws RuntimeException with a message for the assistant.
     */
    private function conversationNamed(User $user, string $name): ChatConversation
    {
        $matches = $this->chats->conversations($user, $name, limit: 5);
        $chosen = self::clearChoice($matches, ltrim($name, '#@'));

        if ($chosen !== null) {
            return $chosen;
        }

        if ($matches === []) {
            throw new RuntimeException("No encuentro ninguna conversación llamada «{$name}». Prueba con find_slack_conversations o pregunta el nombre.");
        }

        throw new RuntimeException("Hay varias conversaciones que encajan con «{$name}»: ".implode(', ', array_map(fn (ChatConversation $conversation): string => $conversation->label(), $matches)).'. Pregúntale cuál.');
    }

    /**
     * @throws RuntimeException with a message for the assistant.
     */
    private function personNamed(User $user, string $name): ChatPerson
    {
        if (self::isRef($name)) {
            return $this->personWithRef($user, $name) ?? throw new RuntimeException("No encuentro a la persona {$name} en Slack.");
        }

        $matches = $this->chats->people($user, ltrim($name, '@'), 5);
        $chosen = self::clearChoice($matches, ltrim($name, '@'));

        if ($chosen !== null) {
            return $chosen;
        }

        if ($matches === []) {
            throw new RuntimeException("No encuentro a «{$name}» en Slack. Pregúntale el nombre completo.");
        }

        throw new RuntimeException("Hay varias personas que encajan con «{$name}»: ".implode(', ', array_map(fn (ChatPerson $person): string => $person->name.($person->title ? " ({$person->title})" : ''), $matches)).'. Pregúntale cuál.');
    }

    private function personWithRef(User $user, string $ref): ?ChatPerson
    {
        return collect($this->chats->people($user, '', PHP_INT_MAX))->firstWhere('ref', $ref);
    }

    /**
     * How to show a conversation or person given by id: "#producto", "Marta Ruiz".
     */
    private function labelFor(User $user, string $ref): string
    {
        return collect($this->chats->conversations($user, '', limit: PHP_INT_MAX))->firstWhere('ref', $ref)?->label()
            ?? $this->personWithRef($user, $ref)?->name
            ?? $ref;
    }

    /**
     * The match meant, if it is clear: the only one, the only one named
     * exactly like that, or the one the user talks to far more than the rest
     * ("Marta" is the Marta they write to every day).
     *
     * @template T of ChatConversation|ChatPerson
     *
     * @param  list<T>  $matches  Best first.
     * @return ?T
     */
    private static function clearChoice(array $matches, string $name): ChatConversation|ChatPerson|null
    {
        $exact = array_values(array_filter($matches, fn (ChatConversation|ChatPerson $match): bool => NameMatch::normalise($match->name) === NameMatch::normalise($name)));

        return match (true) {
            count($matches) === 1 => $matches[0],
            count($exact) === 1 => $exact[0],
            count($matches) > 1 && $matches[0]->activity > 0 && $matches[0]->activity >= 3 * $matches[1]->activity => $matches[0],
            default => null,
        };
    }

    /**
     * @param  list<ChatMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function forAssistant(array $messages, ToolContext $context): array
    {
        return array_map(fn (ChatMessage $message): array => $message->toAssistant($context->timezone()), $messages);
    }
}
