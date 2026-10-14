<?php

namespace App\Voice\Tools\Wingman;

use App\Models\User;
use App\Models\WingmanAction;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use App\Wingman\Notes\MeetingNotes;

class ReviewMeeting implements VoiceTool
{
    use UsesMeetings;

    public function name(): string
    {
        return 'review_meeting';
    }

    public function description(): string
    {
        return 'Muestra el documento de una reunión que siguió Wingman: resumen, decisiones, preguntas abiertas, compromisos y lo que queda por hacer, numerado. '
            .'Sin argumentos, la última. title: palabras del título, de los asistentes o del tema («la de prepago», «la reunión con Marta»). Para «repásame lo de la reunión» o «abre las notas de la reunión con Marta».';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Palabras del título, de los asistentes o del tema.'],
                'meeting_id' => ['type' => 'integer', 'description' => 'El meeting_id, si ya lo tienes.'],
            ],
        ];
    }

    public function rules(): array
    {
        return ['title' => ['nullable', 'string', 'max:200'], 'meeting_id' => ['nullable', 'integer']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $meeting = $this->meetingNamed($user, $arguments['meeting_id'] ?? null, $arguments['title'] ?? null);

        if ($meeting === null) {
            return ToolResult::error('No encuentro esa reunión entre las que siguió Wingman.');
        }

        $notes = MeetingNotes::fromArray($meeting->notes);

        return new ToolResult(
            output: array_filter([
                'meeting_id' => $meeting->id,
                'title' => $meeting->title,
                'status' => $meeting->status->label(),
                'summary' => $notes->summary ?: null,
                'decisions' => $notes->decisions ?: null,
                'open_questions' => $notes->questions ?: null,
                'commitments' => $notes->commitments ?: null,
                'to_do' => $this->openActions($meeting)->values()->map(fn (WingmanAction $action, int $index): array => ['number' => $index + 1, 'what' => $action->title, 'state' => $action->state()])->all() ?: null,
                'note' => $meeting->closed_at === null && $meeting->status->value === 'ended' ? 'Wingman todavía está cerrando la reunión: lo que queda por hacer puede cambiar en unos segundos.' : null,
                'next_step' => 'Resume en 1-3 frases. Para hacer una cosa de la lista, usa propose_meeting_action con su número; para quitarlas, dismiss_meeting_actions.',
            ], fn (mixed $value): bool => $value !== null),
            canvas: $this->documentCanvas($meeting),
        );
    }
}
