<?php

namespace App\Alerts\Lessons;

use App\Models\ProposalOutcome;

/**
 * For tests and demo mode: a lesson from each correction, without a model.
 */
class FakeLessonWriter implements LessonWriter
{
    public function write(ProposalOutcome $outcome): array
    {
        $who = $outcome->inboxItem?->actor_name;

        return match ($outcome->outcome) {
            ProposalOutcome::EDITED => [['layer' => 'reply', 'about' => $who, 'topic' => 'draft', 'body' => "Con {$who}, escribe como en su última respuesta corregida: «".mb_substr((string) ($outcome->detail['sent'] ?? ''), 0, 120).'».']],
            ProposalOutcome::PEOPLE_CHOSEN => [['layer' => 'scheduler', 'about' => $who, 'topic' => 'people', 'body' => "En las llamadas que pide {$who} suelen estar: ".implode(', ', $outcome->detail['people'] ?? []).'.']],
            ProposalOutcome::DURATION_CHANGED => [['layer' => 'scheduler', 'about' => $who, 'topic' => 'duration', 'body' => "Con {$who}, ".($outcome->class === 'task' ? 'las tareas' : 'las llamadas')." duran {$outcome->detail['minutes']} minutos."]],
            default => [],
        };
    }
}
