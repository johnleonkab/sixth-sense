<?php

namespace App\Voice\Tools\Calendar;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ProposeEventResponse implements VoiceTool
{
    use ProposesCalendarChanges;

    private const LABELS = ['accepted' => 'Asistiré', 'declined' => 'No asistiré', 'tentative' => 'Quizá'];

    public function name(): string
    {
        return 'propose_event_response';
    }

    public function description(): string
    {
        return 'Propone responder a una invitación: accepted (asistiré), declined (no asistiré) o tentative (quizá), con un comentario opcional para el organizador. '
            .'Se muestra como borrador y NO se envía hasta que el usuario lo confirme. Solo para reuniones a las que está invitado, no las que organiza.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => self::eventIdParameter(),
                'response' => ['type' => 'string', 'enum' => array_keys(self::LABELS)],
                'comment' => ['type' => 'string', 'description' => 'Comentario opcional para el organizador ("llego 10 minutos tarde").'],
            ],
            'required' => ['event_id', 'response'],
        ];
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:500'],
            'response' => ['required', 'in:'.implode(',', array_keys(self::LABELS))],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $event = $this->changeableEvent($user, $arguments['event_id'], $context, mustOrganize: false);

        if ($event instanceof ToolResult) {
            return $event;
        }

        if ($event->isOrganizer) {
            return ToolResult::error("El usuario organiza «{$event->title}»: no hay invitación que responder. Si quiere anularla, usa propose_event_cancellation.");
        }

        $label = self::LABELS[$arguments['response']];

        return $this->proposeChange(
            $user,
            'respond',
            "{$label} a «{$event->title}»",
            ['ref' => $event->ref, 'response' => $arguments['response'], 'comment' => $arguments['comment'] ?? null],
            $event,
            ['response' => $arguments['response'], 'response_label' => $label, 'previous_response' => $event->myResponse, 'comment' => $arguments['comment'] ?? null],
        );
    }
}
