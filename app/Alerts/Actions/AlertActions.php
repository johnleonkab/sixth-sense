<?php

namespace App\Alerts\Actions;

use App\Alerts\AlertFollowUp;
use App\Events\InboxItemUpdated;
use App\Models\AgendaTask;
use App\Models\InboxItem;
use App\Scheduling\AgendaTaskStatus;
use App\Scheduling\Scheduler;
use Illuminate\Support\Str;

/**
 * The person settles what an alert's action is missing, with one tap or a
 * word: "sí" makes a doubt sure and prepares it; "no" takes it off, and the
 * classifier learns from it next time. For a call, who takes part and how
 * long it lasts; changing either looks for a slot again.
 */
class AlertActions
{
    private const CLASS_NAMES = [
        ClassifiedAction::CALL => 'una llamada',
        ClassifiedAction::TASK => 'una tarea',
        ClassifiedAction::QUESTION => 'una pregunta que investigar',
        ClassifiedAction::REPLY => 'una respuesta',
    ];

    public function __construct(private AlertFollowUp $followUp, private Scheduler $scheduler) {}

    /**
     * @param  ?bool  $yes  The answer to a doubt; null when only completing the action.
     * @param  array{people?: list<string>, duration_minutes?: int}  $changes
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

        if ($yes === false) {
            array_splice($actions, $index, 1);
            $item->user->intentLessons()->create([
                'inbox_item_id' => $item->id,
                'body' => Str::limit("Un mensaje de {$item->actor_name} como «{$item->title}» no pedía ".self::CLASS_NAMES[$action->class]." ({$action->summary}).", 240),
            ]);
            $item->update(['proposal' => [...$proposal, 'actions' => array_values($actions)]]);
            InboxItemUpdated::dispatch($item);

            return null;
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
