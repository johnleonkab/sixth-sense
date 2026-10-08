<?php

namespace App\Voice\Tools\Slack;

use App\Connectors\Chat\ChatPerson;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class FindSlackPeople implements VoiceTool
{
    use UsesChats;

    public function name(): string
    {
        return 'find_slack_people';
    }

    public function description(): string
    {
        return 'Busca personas en el directorio de Slack por nombre, usuario o email, y devuelve su nombre, cargo y email, primero quienes encajan mejor y, entre ellos, con quienes más habla. '
            .'Úsala para "¿cuál es el email de Lucía?" o antes de otras acciones que necesiten a alguien (escribirle, invitarle a una reunión).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string', 'description' => 'Nombre, usuario o email.']],
            'required' => ['query'],
        ];
    }

    public function rules(): array
    {
        return ['query' => ['required', 'string', 'max:120']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $people = $this->chats->people($user, $arguments['query'], 8);

        return new ToolResult([
            'people' => array_map(fn (ChatPerson $person): array => array_filter([
                'person_id' => $person->ref,
                'name' => $person->name,
                'title' => $person->title,
                'email' => $person->email,
                'slack_user' => $person->handle,
                'talks_often' => $person->activity > 0,
            ]), $people),
            'note' => $people === [] ? 'No hay nadie con ese nombre en Slack.' : null,
        ]);
    }
}
