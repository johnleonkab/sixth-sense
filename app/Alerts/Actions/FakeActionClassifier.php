<?php

namespace App\Alerts\Actions;

use App\Alerts\Replies\AlertBrief;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * For tests and demo mode: classifies by plain words, so each flow can be
 * shown without a model. A call when a call is asked for; a task when
 * something is asked by a date; a question when there is a question mark;
 * otherwise a reply.
 */
class FakeActionClassifier implements ActionClassifier
{
    /** @var list<AlertBrief> */
    public array $classified = [];

    /** @var list<ClassifiedAction>|null Fixed answer for a test, instead of the words. */
    public ?array $answer = null;

    public function classify(User $user, AlertBrief $brief): array
    {
        $this->classified[] = $brief;

        if ($this->answer !== null) {
            return $this->answer;
        }

        $text = Str::lower(Str::ascii($brief->subject.' '.$brief->text));
        $name = strtok($brief->from, ' <') ?: $brief->from;
        $actions = [];

        if (preg_match('/llamada|call|lo vemos|reunion/', $text)) {
            $actions[] = new ClassifiedAction(ClassifiedAction::CALL, ClassifiedAction::SURE, "{$name} quiere una llamada.", data: ['people' => [$name], 'people_sure' => true, 'duration_minutes' => 30, 'urgency' => 'this_week']);
        }

        if (preg_match('/informe|revis|mandame|antes del/', $text)) {
            $actions[] = new ClassifiedAction(ClassifiedAction::TASK, ClassifiedAction::SURE, "Revisar lo que pide {$name}", data: ['deliverable' => 'Revisión', 'expected_by' => $name, 'deadline' => now()->addDays(2)->toDateString(), 'origin' => 'asked', 'duration_minutes' => 30]);
        }

        if (str_contains($text, '?') && $actions === []) {
            $actions[] = new ClassifiedAction(ClassifiedAction::QUESTION, ClassifiedAction::SURE, "{$name} pregunta algo.", data: ['about' => 'other', 'terms' => []]);
        }

        return $actions === [] ? [new ClassifiedAction(ClassifiedAction::REPLY, ClassifiedAction::SURE, "Responder a {$name}.")] : $actions;
    }
}
