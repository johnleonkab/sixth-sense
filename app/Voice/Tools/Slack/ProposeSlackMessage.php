<?php

namespace App\Voice\Tools\Slack;

use App\Actions\Actions;
use App\Connectors\Chat\ChatMessage;
use App\Connectors\Chat\Chats;
use App\Connectors\Chat\OutgoingChatMessage;
use App\Models\User;
use App\Voice\Tools\Canvas;
use App\Voice\Tools\Docs\ProposesChanges;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proposes a Slack message as the user. Nothing is sent until they confirm.
 */
class ProposeSlackMessage implements VoiceTool
{
    use ProposesChanges, UsesChats;

    public function __construct(
        private Chats $chats,
        private Actions $actions,
    ) {}

    public function name(): string
    {
        return 'propose_slack_message';
    }

    public function description(): string
    {
        return 'Prepara un mensaje de Slack escrito como el usuario y lo muestra como borrador. NO lo envía: el usuario tiene que confirmarlo. '
            .'to: canal o persona (id o nombre: "#producto", "Marta" para un mensaje directo). '
            .'reply_to: message_id o thread_id para responder dentro de su hilo (entonces to no hace falta). '
            .'mentions: personas a etiquetar; escribe "@Nombre" en el texto donde vayan. Breve y en el tono del usuario.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'to' => ['type' => 'string', 'description' => 'Canal o persona: nombre, conversation_id o person_id.'],
                'text' => ['type' => 'string', 'description' => 'El mensaje.'],
                'reply_to' => ['type' => 'string', 'description' => 'message_id o thread_id al que responder en hilo.'],
                'mentions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Personas a etiquetar, por nombre o person_id.'],
            ],
            'required' => ['text'],
        ];
    }

    public function rules(): array
    {
        return [
            'to' => ['nullable', 'string', 'max:200'],
            'text' => ['required', 'string', 'max:4000'],
            'reply_to' => ['nullable', 'string', 'max:200'],
            'mentions' => ['nullable', 'array', 'max:10'],
            'mentions.*' => ['string', 'max:120'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        try {
            [$to, $label, $threadRef, $threadPreview] = $this->destination($user, $arguments, $context);
            $mentions = array_map(fn (string $name): array => ['ref' => ($person = $this->personNamed($user, $name))->ref, 'name' => $person->name], $arguments['mentions'] ?? []);
        } catch (RuntimeException $exception) {
            return ToolResult::error($exception->getMessage());
        }

        if (! $this->chats->canSend($user, $to)) {
            return ToolResult::error('Esta cuenta de Slack no tiene permiso para enviar mensajes. El usuario puede activarlo en Configuración (Conexiones) y volver a conectarla.');
        }

        $message = new OutgoingChatMessage($to, $label, $arguments['text'], $mentions, $threadRef);
        $action = $this->actions->propose($user, 'chat.send', ($threadRef ? 'Responder en el hilo de ' : 'Mensaje a ').$label, ['message' => $message->toArray()]);

        return new ToolResult(
            output: $this->proposalOutput($action, ['to' => $label, 'in_thread' => $threadRef !== null, 'mentions' => implode(', ', array_column($mentions, 'name')) ?: null]),
            canvas: new Canvas('slack.draft', [
                'action' => $action->toState(),
                'to' => $label,
                'text' => $arguments['text'],
                'mentions' => array_column($mentions, 'name'),
                'thread' => $threadPreview,
            ]),
        );
    }

    /**
     * Where the message goes: a thread, a conversation or a person.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{string, string, ?string, ?array<string, mixed>}
     */
    private function destination(User $user, array $arguments, ToolContext $context): array
    {
        if (filled($arguments['reply_to'] ?? null)) {
            $conversationRef = Str::beforeLast($arguments['reply_to'], ':');
            $view = $this->chats->view($user, $conversationRef, $arguments['reply_to']);
            $parent = $view ? collect($view->messages)->firstWhere('ref', $arguments['reply_to']) : null;

            if ($view === null || $parent === null) {
                throw new RuntimeException('No encuentro ese mensaje para responder en su hilo. Muéstralo de nuevo para obtener su message_id.');
            }

            /** @var ChatMessage $parent */
            return [$conversationRef, $view->conversation->label(), $parent->threadRef ?? $parent->ref, $parent->toCanvas($context->timezone())];
        }

        $to = (string) ($arguments['to'] ?? '');

        if ($to === '') {
            throw new RuntimeException('Dime a quién o a qué canal enviarlo.');
        }

        if (self::isRef($to)) {
            return [$to, $this->labelFor($user, $to), null, null];
        }

        try {
            $conversation = $this->conversationNamed($user, $to);

            return [$conversation->ref, $conversation->label(), null, null];
        } catch (RuntimeException) {
            $person = $this->personNamed($user, $to);

            return [$person->ref, $person->name, null, null];
        }
    }
}
