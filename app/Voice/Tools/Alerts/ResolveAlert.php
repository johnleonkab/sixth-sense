<?php

namespace App\Voice\Tools\Alerts;

use App\Alerts\AlertFollowUp;
use App\Alerts\AlertStatus;
use App\Alerts\Outcomes\ProposalOutcomes;
use App\Alerts\Triage;
use App\Events\InboxItemUpdated;
use App\Models\ProposalOutcome;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Close an alert the person says is done, or that needs nothing; or open it
 * again. The alert is shown with where it now stands.
 */
class ResolveAlert implements VoiceTool
{
    use DescribesAlerts;

    public function __construct(private AlertFollowUp $followUp, private ProposalOutcomes $outcomes) {}

    public function name(): string
    {
        return 'resolve_alert';
    }

    public function description(): string
    {
        return 'Cambia el estado de un aviso cuando el usuario lo pide: "done" si ya lo ha resuelto por su cuenta (lo respondió de palabra, en otro sitio…), '
            .'"dismissed" si no hace falta hacer nada, y "reopen" para volver a dejarlo pendiente. No hace falta usarla tras responder desde aquí: esos avisos se resuelven solos.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'alert_id' => ['type' => 'integer'],
                'outcome' => ['type' => 'string', 'enum' => ['done', 'dismissed', 'reopen']],
                'reason' => ['type' => 'string', 'enum' => ProposalOutcome::REASONS, 'description' => 'Al descartar, por qué: no_important (no era importante), not_for_me (no era para él), bad_proposal (importaba, pero la propuesta estaba mal).'],
            ],
            'required' => ['alert_id', 'outcome'],
        ];
    }

    public function rules(): array
    {
        return [
            'alert_id' => ['required', 'integer'],
            'outcome' => ['required', 'in:done,dismissed,reopen'],
            'reason' => ['nullable', 'in:'.implode(',', ProposalOutcome::REASONS)],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $item = $user->inboxItems()->find($arguments['alert_id']);

        if ($item === null) {
            return ToolResult::error('No encuentro ese aviso.');
        }

        $say = match (true) {
            $arguments['outcome'] === 'reopen' && $item->status === AlertStatus::Pending => 'Ese aviso ya estaba pendiente.',
            $arguments['outcome'] === 'reopen' => 'Hecho, vuelve a estar pendiente.',
            $item->status !== AlertStatus::Pending => 'Ese aviso ya estaba cerrado.',
            $arguments['outcome'] === 'done' => 'Hecho, lo doy por resuelto.',
            default => 'Vale, lo descarto.',
        };

        $changes = match (true) {
            $arguments['outcome'] === 'reopen' && $item->status !== AlertStatus::Pending => [
                // Something judged unimportant can be made to wait for an answer too.
                'status' => AlertStatus::Pending, 'triage' => Triage::Important, 'resolution' => null, 'resolved_at' => null,
            ],
            $arguments['outcome'] !== 'reopen' && $item->status === AlertStatus::Pending => [
                'status' => $arguments['outcome'] === 'done' ? AlertStatus::Resolved : AlertStatus::Dismissed,
                'resolution' => $arguments['outcome'] === 'done' ? 'marked_done' : 'dismissed',
                'resolved_at' => now(),
                'planning' => false,
            ],
            default => null,
        };

        if ($changes !== null) {
            $item->update($changes);
            InboxItemUpdated::dispatch($item);

            match ($arguments['outcome']) {
                'done' => $this->outcomes->resolved($item, 'marked_done'),
                'dismissed' => $this->outcomes->discarded($item, $arguments['reason'] ?? null),
                default => $this->outcomes->reopened($item),
            };
        }

        return new ToolResult(
            output: ['status' => $item->stateLabel(), 'say' => $say],
            canvas: $this->reviewCanvas($item, $this->followUp->brief($item)),
        );
    }
}
