<?php

namespace App\Alerts\Actions;

use App\Alerts\AlertFollowUp;
use App\Alerts\Lessons\Lessons;
use App\Alerts\Outcomes\ProposalOutcomes;
use App\Events\InboxItemUpdated;
use App\Models\AgendaTask;
use App\Models\InboxItem;
use App\Models\LearnedEvalCase;
use App\Models\ProposalOutcome;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Scheduler;
use Illuminate\Support\Str;

/**
 * The person settles what an alert's action is missing, with one tap or a
 * word: "sí" makes a doubt sure and prepares it; "no" takes it off, and the
 * classifier learns from it next time. For a call, who takes part and how
 * long it lasts; for a task, how long and which of its slots. Changing who
 * or how long looks for a slot again.
 */
class AlertActions
{
    private const CLASS_NAMES = [
        ClassifiedAction::CALL => 'una llamada',
        ClassifiedAction::TASK => 'una tarea',
        ClassifiedAction::QUESTION => 'una pregunta que investigar',
        ClassifiedAction::REPLY => 'una respuesta',
    ];

    public function __construct(
        private AlertFollowUp $followUp,
        private Scheduler $scheduler,
        private Lessons $lessons,
        private ProposalOutcomes $outcomes,
    ) {}

    /**
     * @param  ?bool  $yes  The answer to a doubt; null when only completing the action.
     * @param  array{people?: list<string>, duration_minutes?: int, slot?: int, choice?: int}  $changes  slot: another of the slots offered for a task;
     *                                                                                                   choice: which of several candidates answers a question.
     * @return ?ClassifiedAction The action as it now stands, or null when it is not on the alert (or was taken off).
     */
    public function settle(InboxItem $item, string $key, ?bool $yes, array $changes = []): ?ClassifiedAction
    {
        $proposal = $item->proposal ?? [];
        $actions = $proposal['actions'] ?? [];
        $index = collect($actions)->search(fn (array $action): bool => ($action['key'] ?? null) === $key);

        if ($index === false) {
            return null;
        }

        $action = ClassifiedAction::fromArray($actions[$index]);

        if (isset($changes['choice'])) {
            return $this->followUp->answerQuestion($item, $changes['choice']) ? $action : null;
        }

        if (isset($changes['slot'])) {
            $task = $this->openTask($item);

            return $task !== null && $this->scheduler->choose($task, $changes['slot']) !== null ? $action : null;
        }

        if ($yes === false) {
            array_splice($actions, $index, 1);
            $this->lessons->learn($item->user, "Un mensaje de {$item->actor_name} como «{$item->title}» no pedía ".self::CLASS_NAMES[$action->class]." ({$action->summary}).", 'classifier', $item->actor_name, "not-{$action->class}", $item->id);
            $this->evalCase($item, array_column($actions, 'class'));
            $item->update(['proposal' => [...$proposal, 'actions' => array_values($actions)]]);
            InboxItemUpdated::dispatch($item);

            return null;
        }

        // A doubt that was right: the classifier should have been sure.
        if ($yes === true && ! $action->sure()) {
            $this->evalCase($item, array_column($actions, 'class'), $action->class);
        }

        if (isset($changes['people'])) {
            $this->outcomes->adjusted($item, ProposalOutcome::PEOPLE_CHOSEN, $action->class, ['people' => array_values($changes['people'])]);
        }

        if (isset($changes['duration_minutes']) && $changes['duration_minutes'] !== ($action->data['duration_minutes'] ?? null)) {
            $this->outcomes->adjusted($item, ProposalOutcome::DURATION_CHANGED, $action->class, ['minutes' => $changes['duration_minutes'], 'was' => $action->data['duration_minutes'] ?? null]);
        }

        $brief = $this->followUp->brief($item);
        $wasSure = $action->sure();
        $action = new ClassifiedAction($action->class, ClassifiedAction::SURE, $action->summary, null, $this->complete($action->data, $changes));

        // A call made sure now still needs its people and length checked.
        if (! $wasSure && $action->class === ClassifiedAction::CALL && $brief !== null) {
            $action = $this->followUp->prepareCall($item, $action, $brief);
        }

        $actions[$index] = $action->toArray($key);
        $item->update(['proposal' => [...$proposal, 'actions' => array_values($actions)]]);
        InboxItemUpdated::dispatch($item);

        // What was already looked for with other people or another length is withdrawn first.
        if ($changes !== [] && ($task = $this->openTask($item)) !== null) {
            $this->scheduler->cancel($task);
        }

        $this->followUp->schedule($item->refresh(), $action, $brief);

        return $action;
    }

    /**
     * The message joins the classifier's evaluation set with what it really asked.
     *
     * @param  list<string>  $classes  The classes left on the alert.
     */
    private function evalCase(InboxItem $item, array $classes, ?string $sure = null): void
    {
        LearnedEvalCase::query()->create([
            'user_id' => $item->user_id,
            'agent' => 'clasificador',
            'case' => array_filter([
                'ask' => Str::limit(trim((string) ($item->body ?: $item->title)), 1000),
                'from' => $item->actor_name,
                'classes' => array_values(array_unique(array_diff($classes, [ClassifiedAction::REPLY]))),
                'confidence' => $sure !== null && in_array($sure, [ClassifiedAction::CALL, ClassifiedAction::TASK], true) ? ClassifiedAction::SURE : null,
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{people?: list<string>, duration_minutes?: int}  $changes
     * @return array<string, mixed>
     */
    private function complete(array $data, array $changes): array
    {
        if (isset($changes['people'])) {
            $data['people'] = array_values($changes['people']);
            $data['people_sure'] = true;
            $data['candidates'] = array_map(fn (array $candidate): array => [...$candidate, 'checked' => in_array($candidate['name'], $changes['people'], true)], $data['candidates'] ?? []);
            $data['needs'] = array_values(array_diff($data['needs'] ?? [], ['people']));
        }

        if (isset($changes['duration_minutes'])) {
            $data['duration_minutes'] = $changes['duration_minutes'];
            $data['needs'] = array_values(array_diff($data['needs'] ?? [], ['duration']));
        }

        if (($data['needs'] ?? null) === []) {
            unset($data['needs']);
        }

        return $data;
    }

    /**
     * The call or task still being looked for, or proposed, for this alert.
     */
    private function openTask(InboxItem $item): ?AgendaTask
    {
        return $item->agendaTask()->whereIn('status', [AgendaTaskStatus::Assessing, AgendaTaskStatus::Proposed, AgendaTaskStatus::Offered, AgendaTaskStatus::Conflict])->first();
    }
}
