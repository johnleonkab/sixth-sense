<?php

namespace App\Voice\Tools\Slack;

use App\Connectors\Chat\ChatConversation;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class FindSlackConversations implements VoiceTool
{
    use UsesChats;

    public function name(): string
    {
        return 'find_slack_conversations';
    }

    public function description(): string
    {
        return 'Busca los canales, canales privados, mensajes directos y grupos de Slack del usuario por nombre, y devuelve su conversation_id. '
            .'Úsala para identificar un chat antes de mostrarlo o escribir en él.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Nombre del canal o de la persona; vacío para listar.'],
                'kind' => ['type' => 'string', 'enum' => ChatConversation::KINDS, 'description' => 'channel, private, dm (mensaje directo) o group.'],
            ],
        ];
    }

    public function rules(): array
    {
        return ['query' => ['nullable', 'string', 'max:120'], 'kind' => ['nullable', 'in:'.implode(',', ChatConversation::KINDS)]];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $conversations = $this->chats->conversations($user, $arguments['query'] ?? '', $arguments['kind'] ?? null, 15);

        return new ToolResult([
            'conversations' => array_map(fn (ChatConversation $conversation): array => array_filter([
                'conversation_id' => $conversation->ref,
                'name' => $conversation->label(),
                'kind' => $conversation->kind,
                'topic' => $conversation->topic,
                'workspace' => $conversation->workspace,
                'talks_often' => $conversation->activity > 0,
            ]), $conversations),
        ]);
    }
}
