<?php

namespace App\Voice\Tools\Wingman;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use App\Wingman\Closing\MeetingActions;

class DismissMeetingActions implements VoiceTool
{
    use UsesMeetings;

    public function __construct(private MeetingActions $actions) {}

    public function name(): string
    {
        return 'dismiss_meeting_actions';
    }

    public function description(): string
    {
        return 'Quita de lo que queda por hacer de una reunión de Wingman las cosas que el usuario no quiere, por sus números de review_meeting, o todas las que siguen sin hacer (all). Para «descarta las demás».';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'numbers' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Sus números en la lista.'],
                'all' => ['type' => 'boolean', 'description' => 'Todas las que siguen sin hacer.'],
                'meeting_id' => ['type' => 'integer', 'description' => 'El meeting_id; sin él, la última reunión.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'numbers' => ['nullable', 'array', 'max:20'],
            'numbers.*' => ['integer', 'min:1'],
            'all' => ['nullable', 'boolean'],
            'meeting_id' => ['nullable', 'integer'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $meeting = $this->meetingNamed($user, $arguments['meeting_id'] ?? null, null);

        if ($meeting === null) {
            return ToolResult::error('No encuentro esa reunión entre las que siguió Wingman.');
        }

        $open = $this->openActions($meeting);
        $chosen = ($arguments['all'] ?? false)
            ? $open->filter(fn ($action): bool => $action->state() === 'proposed')
            : collect($arguments['numbers'] ?? [])->map(fn (int $number) => $open[$number - 1] ?? null)->filter();

        if ($chosen->isEmpty()) {
            return ToolResult::error('No hay nada que quitar con esos números.');
        }

        $chosen->each(fn ($action) => $this->actions->dismiss($action, true));

        return new ToolResult(
            output: ['dismissed' => $chosen->pluck('title')->values()->all(), 'note' => 'Se pueden recuperar desde la página de la reunión.'],
            canvas: $this->documentCanvas($meeting->refresh()),
        );
    }
}
