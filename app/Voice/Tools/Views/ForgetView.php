<?php

namespace App\Voice\Tools\Views;

use App\Models\User;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Deletes a saved view. It only exists in sixth-sense, so it needs no "sí".
 */
class ForgetView implements VoiceTool
{
    public function name(): string
    {
        return 'forget_view';
    }

    public function description(): string
    {
        return 'Borra una vista guardada por su nombre.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Nombre de la vista guardada.'],
            ],
            'required' => ['name'],
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $view = OpenSavedView::closest($user->savedViews()->get()->all(), $arguments['name']);

        if ($view === null) {
            return ToolResult::error("No hay ninguna vista guardada como «{$arguments['name']}».");
        }

        $view->delete();

        return new ToolResult(['forgotten' => $view->name, 'say' => "Borrada «{$view->name}»."]);
    }
}
