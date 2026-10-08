<?php

namespace App\Voice\Tools\Calendar;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ProposeEventCancellation implements VoiceTool
{
    use ProposesCalendarChanges;

    public function name(): string
    {
        return 'propose_event_cancellation';
    }

    public function description(): string
    {
        return 'Propone cancelar una reunión que organiza el usuario; se borra y se avisa a los invitados. Se muestra como borrador y NO se cancela hasta que el usuario lo confirme. '
            .'Si el usuario no la organiza, no puede cancelarla: ofrécele rechazarla con propose_event_response.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => ['event_id' => self::eventIdParameter()],
            'required' => ['event_id'],
        ];
    }

    public function rules(): array
    {
        return ['event_id' => ['required', 'string', 'max:500']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $event = $this->changeableEvent($user, $arguments['event_id'], $context, mustOrganize: true);

        if ($event instanceof ToolResult) {
            return $event;
        }

        $others = count(array_filter($event->attendees, fn (array $attendee): bool => ($attendee['response'] ?? null) !== null && $attendee['email'] !== null));

        return $this->proposeChange(
            $user,
            'cancel',
            "Cancelar «{$event->title}»",
            ['ref' => $event->ref],
            $event,
            ['notified' => max(0, $others - 1)],
        );
    }
}
