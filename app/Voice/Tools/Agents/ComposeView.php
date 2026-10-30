<?php

namespace App\Voice\Tools\Agents;

use App\Models\User;
use App\Voice\OpenUi\OpenUiValidator;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\Views\ShowView;
use App\Voice\Tools\VoiceTool;

/**
 * The Vistas agent's way to put a view on screen: the program is checked with
 * OpenUI's parser first, and an invalid one goes back with its errors to be
 * fixed instead of reaching the screen.
 */
class ComposeView implements VoiceTool
{
    public function __construct(private ShowView $show, private OpenUiValidator $validator) {}

    public function name(): string
    {
        return 'compose_view';
    }

    public function description(): string
    {
        return 'Compone una vista en OpenUI Lang y la valida con el parser antes de mostrarla. Si tiene errores, te los devuelve para que la corrijas. '
            .'Solo datos reales: de una Query a un dataset o de lo que te hayan pasado.';
    }

    public function parameters(): array
    {
        return $this->show->parameters();
    }

    public function rules(): array
    {
        return $this->show->rules();
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        $errors = $this->validator->errors($arguments['ui']);

        if ($errors !== null && $errors !== []) {
            return ToolResult::error('La vista no es válida; corrígela y vuelve a llamar: '.implode('; ', array_slice($errors, 0, 6)));
        }

        return $this->show->handle($user, $arguments, $context);
    }
}
