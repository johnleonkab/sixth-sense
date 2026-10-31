<?php

namespace App\Alerts\Actions;

use App\Alerts\AlertFollowUp;
use App\Events\InboxItemUpdated;
use App\Models\InboxItem;
use Illuminate\Support\Str;

/**
 * The person settles a doubt of the classifier with one tap or a word: "sí"
 * makes the action sure and prepares it; "no" takes it off the alert, and the
 * classifier learns from it next time.
 */
class AlertActions
{
    private const CLASS_NAMES = [
        ClassifiedAction::CALL => 'una llamada',
        ClassifiedAction::TASK => 'una tarea',
        ClassifiedAction::QUESTION => 'una pregunta que investigar',
        ClassifiedAction::REPLY => 'una respuesta',
    ];

    public function __construct(private AlertFollowUp $followUp) {}

    /**
     * @return ?ClassifiedAction The action as it now stands, or null when it is not on the alert (or was taken off).
     */
    public function settle(InboxItem $item, string $key, bool $yes): ?ClassifiedAction
    {
        $proposal = $item->proposal ?? [];
        $actions = $proposal['actions'] ?? [];
        $index = collect($actions)->search(fn (array $action): bool => ($action['key'] ?? null) === $key);

        if ($index === false) {
            return null;
        }

        $action = ClassifiedAction::fromArray($actions[$index]);

        if ($yes) {
            $action = new ClassifiedAction($action->class, ClassifiedAction::SURE, $action->summary, null, $action->data);
            $actions[$index] = $action->toArray($key);
        } else {
            array_splice($actions, $index, 1);
            $item->user->intentLessons()->create([
                'inbox_item_id' => $item->id,
                'body' => Str::limit("Un mensaje de {$item->actor_name} como «{$item->title}» no pedía ".self::CLASS_NAMES[$action->class]." ({$action->summary}).", 240),
            ]);
        }

        $item->update(['proposal' => [...$proposal, 'actions' => array_values($actions)]]);
        InboxItemUpdated::dispatch($item);

        if (! $yes) {
            return null;
        }

        $this->followUp->schedule($item->refresh(), $action, $this->followUp->brief($item));

        return $action;
    }
}
