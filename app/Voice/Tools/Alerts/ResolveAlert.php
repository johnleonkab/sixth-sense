<?php

namespace App\Voice\Tools\Alerts;

use App\Alerts\AlertStatus;
use App\Events\InboxItemUpdated;
use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Close an alert the person says is done, or that needs nothing.
 */
class ResolveAlert implements VoiceTool
{
    public function name(): string
    {
        return 'resolve_alert';
    }

    public function description(): string
    {
        return 'Cierra un aviso cuando el usuario lo pide: "done" si ya lo ha resuelto por su cuenta (lo respondió de palabra, en otro sitio…), '
            .'"dismissed" si no hace falta hacer nada. No hace falta usarla tras responder desde aquí: esos avisos se resuelven solos.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'alert_id' => ['type' => 'integer'],
                'outcome' => ['type' => 'string', 'enum' => ['done', 'dismissed']],
            ],
            'required' => ['alert_id', 'outcome'],
        ];
    }

    public function rules(): array
    {
        return ['alert_id' => ['required', 'integer'], 'outcome' => ['required', 'in:done,dismissed']];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $item = $user->inboxItems()->find($arguments['alert_id']);

        if ($item === null) {
            return ToolResult::error('No encuentro ese aviso.');
        }

        if ($item->status !== AlertStatus::Pending) {
            return new ToolResult(['status' => $item->status->label(), 'say' => 'Ese aviso ya estaba cerrado.']);
        }

        $done = $arguments['outcome'] === 'done';
        $item->close($done ? AlertStatus::Resolved : AlertStatus::Dismissed, $done ? 'marked_done' : 'dismissed');
        InboxItemUpdated::dispatch($item);

        return new ToolResult(['status' => $item->status->label(), 'say' => $done ? 'Hecho, lo doy por resuelto.' : 'Vale, lo descarto.']);
    }
}
