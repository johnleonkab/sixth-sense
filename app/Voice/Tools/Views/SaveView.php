<?php

namespace App\Voice\Tools\Views;

use App\Models\User;
use App\Voice\OpenUi\OpenUi;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * Saves the composed view on screen under a name, to open it again another
 * day. Saving the same name again replaces it. Nothing leaves sixth-sense.
 */
class SaveView implements VoiceTool
{
    public function name(): string
    {
        return 'save_view';
    }

    public function description(): string
    {
        return 'Guarda con un nombre la última vista compuesta que hay en pantalla (resultados, analítica, una vista hecha con show_view) para abrirla otro día con open_view. '
            .'Con un nombre que ya existe, la sustituye. Los datos son los de ahora.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Nombre corto, como lo dice el usuario ("mi lunes", "tráfico tickets").'],
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
        $view = OpenUi::lastView($user);

        if ($view === null) {
            return ToolResult::error('No hay ninguna vista compuesta reciente que guardar. Las listas, la analítica y las vistas de show_view se pueden guardar.');
        }

        $name = trim($arguments['name']);
        $saved = $user->savedViews()->updateOrCreate(['name' => $name], [
            'title' => $view['title'],
            'source' => $view['source'],
            'ui' => $view['ui'],
            'items' => $view['items'],
        ]);

        return new ToolResult([
            'saved_as' => $saved->name,
            'title' => $saved->title,
            'replaced' => ! $saved->wasRecentlyCreated,
            'say' => "Guardada como «{$saved->name}».",
        ]);
    }
}
