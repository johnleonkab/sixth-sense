<?php

namespace App\Voice\Tools\Calendar;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

class ProposeEventSummary implements VoiceTool
{
    use ProposesCalendarChanges;

    public function name(): string
    {
        return 'propose_event_summary';
    }

    public function description(): string
    {
        return 'Propone añadir un resumen a una reunión (acuerdos, próximos pasos), al final de su descripción y con la fecha de hoy, sin borrar lo que ya hay. '
            .'Se muestra como borrador y NO se guarda hasta que el usuario lo confirme. Redáctalo claro y breve, en viñetas si ayuda.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event_id' => self::eventIdParameter(),
                'summary' => ['type' => 'string', 'description' => 'El resumen.'],
            ],
            'required' => ['event_id', 'summary'],
        ];
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:500'],
            'summary' => ['required', 'string', 'max:8000'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $event = $this->changeableEvent($user, $arguments['event_id'], $context, mustOrganize: true);

        if ($event instanceof ToolResult) {
            return $event;
        }

        $heading = 'Resumen · '.$context->now->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        return $this->proposeChange(
            $user,
            'summary',
            "Añadir resumen a «{$event->title}»",
            ['ref' => $event->ref, 'summary' => $arguments['summary'], 'heading' => $heading],
            $event,
            ['heading' => $heading, 'summary' => $arguments['summary'], 'existing_description' => $event->description],
        );
    }
}
