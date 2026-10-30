<?php

namespace App\Voice\Tools\Views;

use App\Models\User;
use App\Voice\OpenUi\OpenUi;
use App\Voice\Tools\ToolContext;
use App\Voice\Tools\ToolResult;
use App\Voice\Tools\VoiceTool;

/**
 * How to put live data in a composed view, asked for only when a view needs
 * it, so the prompt every turn carries stays short.
 */
class ViewDataGuide implements VoiceTool
{
    public function name(): string
    {
        return 'view_data_guide';
    }

    public function description(): string
    {
        return 'Devuelve cómo usar datos vivos en una vista de show_view: Query, $variables con Tabs, las funciones (@Count, @GroupCount…) y cada dataset con sus campos. '
            .'Llámala antes de componer un gráfico o unas cifras con datos vivos, o de cambiar una vista que tenga Query o $variables.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function rules(): array
    {
        return [];
    }

    public function handle(User $user, array $arguments, ToolContext $context): ToolResult
    {
        return new ToolResult(['guide' => OpenUi::dataGuide()]);
    }
}
