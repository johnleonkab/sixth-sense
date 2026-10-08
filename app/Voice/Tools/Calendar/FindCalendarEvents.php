<?php

namespace App\Voice\Tools\Calendar;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Reads events so the assistant can answer by voice, without changing the screen.
 */
class FindCalendarEvents implements VoiceTool
{
    use ReadsCalendar;

    public function name(): string
    {
        return 'find_calendar_events';
    }

    public function description(): string
    {
        return 'Consulta los eventos del calendario del usuario para responder SOLO por voz, sin mostrar nada en pantalla. '
            .'Úsala para preguntas concretas: "¿a qué hora es la daily?", "¿tengo algo con Marta esta semana?", "¿estoy libre el jueves por la tarde?". '
            .'Si el usuario pide ver, enseñar o abrir el calendario, usa show_calendar en su lugar; no llames a las dos. '
            .'No la uses si la pregunta no tiene que ver con su agenda.';
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $query = $this->query($arguments, $context);

        if ($this->exceedsMaxRange($query)) {
            return ToolResult::error('El rango es demasiado largo: pide como mucho dos meses.');
        }

        return new ToolResult($this->summary($this->read($user, $query), $query));
    }
}
