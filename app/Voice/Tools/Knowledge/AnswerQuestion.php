<?php

namespace App\Voice\Tools\Knowledge;

use App\Alerts\AlertSource;
use App\Knowledge\Gaps\GapQuestions;
use App\Knowledge\Gaps\GapStatus;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Saves what the person answered to one of the system's questions (an alert
 * of type Pregunta), or drops the question. Answers become memory every
 * agent reads, and are never asked again.
 */
class AnswerQuestion implements VoiceTool
{
    public function __construct(private GapQuestions $questions) {}

    public function name(): string
    {
        return 'answer_question';
    }

    public function description(): string
    {
        return 'Guarda la respuesta del usuario a una pregunta del sistema (un aviso de tipo Pregunta: qué es una reunión, qué significa una sigla). '
            .'Resume lo que dijo en una o dos frases, en tercera persona y sin perder datos ("Revisión semanal de incidencias con Soporte; John decide prioridades; imprescindible"). '
            .'Con dismiss en true, la descarta y no se vuelve a preguntar. No escribe en ningún servicio, así que no pide confirmación.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'alert_id' => ['type' => 'integer', 'description' => 'id del aviso de tipo Pregunta.'],
                'answer' => ['type' => 'string', 'description' => 'La respuesta, resumida.'],
                'dismiss' => ['type' => 'boolean', 'description' => 'true si no merece la pena responderla.'],
            ],
            'required' => ['alert_id'],
        ];
    }

    public function rules(): array
    {
        return [
            'alert_id' => ['required', 'integer'],
            'answer' => ['nullable', 'string', 'max:2000'],
            'dismiss' => ['nullable', 'boolean'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $item = $user->inboxItems()->where('source', AlertSource::Questions)->find($arguments['alert_id']);
        $gap = $item === null ? null : $user->knowledgeGaps()->find($item->payload['gap_id'] ?? 0);

        if ($gap === null) {
            return ToolResult::error('No encuentro esa pregunta.');
        }

        if (in_array($gap->status, [GapStatus::Answered, GapStatus::Dismissed], true)) {
            return ToolResult::error('Esa pregunta ya está cerrada.');
        }

        if ((bool) ($arguments['dismiss'] ?? false)) {
            $this->questions->dismiss($gap);

            return new ToolResult(['status' => 'dismissed', 'say' => 'Vale, no te lo vuelvo a preguntar.']);
        }

        if (blank($arguments['answer'] ?? null)) {
            return ToolResult::error('Falta la respuesta.');
        }

        $this->questions->answer($gap, $arguments['answer']);

        return new ToolResult(['status' => 'answered', 'memory' => $gap->fresh()->memory(), 'say' => 'Apuntado.']);
    }
}
