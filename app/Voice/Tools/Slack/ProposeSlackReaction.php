<?php

namespace App\Voice\Tools\Slack;

use App\Actions\Actions;
use App\Connectors\Chat\ChatReactions;
use App\Connectors\Chat\Chats;
use App\Models\User;
use App\Voice\OpenUi\Views\ProposalViews;
use App\Voice\Tools\Docs\ProposesChanges;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Proposes reacting to a Slack message as the user (a 👍 instead of "ok").
 * Nothing is added until they confirm.
 */
class ProposeSlackReaction implements VoiceTool
{
    use ProposesChanges;

    public function __construct(private Chats $chats, private Actions $actions) {}

    public function name(): string
    {
        return 'propose_slack_reaction';
    }

    public function description(): string
    {
        return 'Propone reaccionar a un mensaje de Slack con un emoji, como el usuario, cuando basta con eso ("ok", "visto", "gracias"). NO lo hace: espera su sí. '
            .'message_id del mensaje; reaction: '.implode(', ', array_keys(ChatReactions::NAMES)).'.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message_id' => ['type' => 'string'],
                'reaction' => ['type' => 'string', 'enum' => array_keys(ChatReactions::NAMES)],
            ],
            'required' => ['message_id', 'reaction'],
        ];
    }

    public function rules(): array
    {
        return [
            'message_id' => ['required', 'string', 'max:200'],
            'reaction' => ['required', 'in:'.implode(',', array_keys(ChatReactions::NAMES))],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        if (! $this->chats->canReact($user, $arguments['message_id'])) {
            return ToolResult::error('Esta cuenta de Slack no tiene permiso para reaccionar. El usuario puede activar «Reaccionar a mensajes» en Configuración (Conexiones) y volver a conectarla; mientras, responde con un mensaje corto.');
        }

        $emoji = ChatReactions::emoji($arguments['reaction']);
        $alert = $user->inboxItems()->where('external_id', $arguments['message_id'])->first();
        $action = $this->actions->propose($user, 'chat.react', "Reaccionar con {$emoji}", array_filter([
            'message_id' => $arguments['message_id'],
            'reaction' => $arguments['reaction'],
            'inbox_item_id' => $alert?->id,
        ]));

        return new ToolResult(
            output: $this->proposalOutput($action, ['reaction' => $emoji]),
            canvas: ProposalViews::reaction($user, $action, $emoji),
        );
    }
}
