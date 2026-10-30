<?php

namespace App\Voice\Tools\Views;

use App\Models\SavedView;
use App\Models\User;
use App\Voice\OpenUi\OpenUi;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;
use Illuminate\Support\Str;

/**
 * Opens a view the person saved, by its name or close to it. Without a name,
 * lists the saved ones.
 */
class OpenSavedView implements VoiceTool
{
    public function name(): string
    {
        return 'open_view';
    }

    public function description(): string
    {
        return 'Abre una vista que el usuario guardó con save_view, por su nombre o uno parecido. Sin nombre, dice cuáles hay. Muestra los datos de cuando se guardó: dilo si importa.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Nombre de la vista. Vacío para listarlas.'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $views = $user->savedViews()->orderByDesc('opened_at')->orderByDesc('updated_at')->get();
        $wanted = trim((string) ($arguments['name'] ?? ''));

        if ($views->isEmpty()) {
            return ToolResult::error('No hay vistas guardadas todavía. Se guarda una con «guarda esta vista como…».');
        }

        $view = $wanted === '' ? null : self::closest($views->all(), $wanted);

        if ($view === null) {
            return new ToolResult([
                'saved_views' => $views->map(fn (SavedView $view): string => "{$view->name} ({$view->title})")->all(),
                'next_step' => $wanted === '' ? 'Di cuáles hay y pregunta cuál abrir.' : "Ninguna se llama como «{$wanted}». Di cuáles hay.",
            ]);
        }

        $view->update(['opened_at' => now()]);
        OpenUi::rememberItems($user, $view->items ?? []);
        OpenUi::rememberView($user, ['title' => $view->title, 'source' => $view->source, 'ui' => $view->ui, 'items' => $view->items ?? []]);

        return new ToolResult(
            output: [
                'opened' => $view->name,
                'saved_at' => $view->updated_at?->setTimezone($context->timezone())->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm'),
                'shown_on_screen' => true,
            ],
            canvas: $view->canvas(),
        );
    }

    /**
     * The saved view whose name is the wanted one, contains it, or is
     * contained in it, ignoring case and accents.
     *
     * @param  list<SavedView>  $views
     */
    public static function closest(array $views, string $wanted): ?SavedView
    {
        $fold = fn (string $text): string => Str::lower(Str::ascii(trim($text)));
        $wanted = $fold($wanted);

        foreach ($views as $view) {
            if ($fold($view->name) === $wanted) {
                return $view;
            }
        }

        foreach ($views as $view) {
            if (str_contains($fold($view->name), $wanted) || str_contains($wanted, $fold($view->name))) {
                return $view;
            }
        }

        return null;
    }
}
