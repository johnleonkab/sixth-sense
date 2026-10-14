<?php

namespace App\Voice\Tools\Wingman;

use App\Models\User;
use App\Voice\Tools\Docs\ProposesChanges;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use App\Wingman\Closing\MeetingActions;

class ProposeMeetingAction implements VoiceTool
{
    use ProposesChanges, UsesMeetings;

    public function __construct(private MeetingActions $actions) {}

    public function name(): string
    {
        return 'propose_meeting_action';
    }

    public function description(): string
    {
        return 'Prepara para confirmar una de las cosas que quedan por hacer de una reunión de Wingman (mandar el mensaje, convocar, crear el ticket, reservar tiempo), por su número de review_meeting. '
            .'NO la hace: el usuario tiene que decir «sí». Para «ejecuta la 2».';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'number' => ['type' => 'integer', 'description' => 'Su número en la lista de review_meeting.'],
                'meeting_id' => ['type' => 'integer', 'description' => 'El meeting_id; sin él, la última reunión.'],
            ],
            'required' => ['number'],
        ];
    }

    public function rules(): array
    {
        return ['number' => ['required', 'integer', 'min:1'], 'meeting_id' => ['nullable', 'integer']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $meeting = $this->meetingNamed($user, $arguments['meeting_id'] ?? null, null);
        $item = $meeting ? ($this->openActions($meeting)[$arguments['number'] - 1] ?? null) : null;

        if ($item === null) {
            return ToolResult::error('No hay nada con ese número en lo que queda por hacer. Muéstralo con review_meeting.');
        }

        if (in_array($item->state(), ['running', 'done'], true)) {
            return ToolResult::error("Eso ya está {$item->state()}: «{$item->title}».");
        }

        $item = $this->actions->review($item);

        return new ToolResult(
            output: $this->proposalOutput($item->pendingAction, ['what' => $item->title, 'detail' => $item->detail]),
            canvas: $this->documentCanvas($meeting, $item->pendingAction->toState(), $arguments['number']),
        );
    }
}
